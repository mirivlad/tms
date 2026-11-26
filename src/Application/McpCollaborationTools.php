<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Slim\Psr7\UploadedFile;
use Tms\Domain\Attachment\AttachmentPolicy;
use Tms\Domain\Attachment\AttachmentRecord;
use Tms\Domain\Attachment\AttachmentRepository;
use Tms\Domain\Discussion\DiscussionReadRepository;
use Tms\Domain\Discussion\DiscussionRepository;
use Tms\Domain\Project\ProjectAttachmentRecord;
use Tms\Domain\Project\ProjectAttachmentRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\Team\TeamInvitationRecord;
use Tms\Domain\Team\TeamInvitationRepository;
use Tms\Domain\Team\TeamRepository;
use Tms\Domain\User\UserRepository;
use Tms\Infrastructure\AttachmentStorage;
use Tms\Security\TaskDescriptionSanitizer;

final class McpCollaborationTools
{
    private ?string $pendingUpload = null;
    private const MAX_CHUNK = 32768;
    private const MAX_UPLOAD = 131072;

    public function __construct(
        private readonly PDO $db,
        private readonly TeamRepository $teams,
        private readonly TeamInvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly TeamInvitationDeliveryService $invitationDelivery,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly DiscussionRepository $discussions,
        private readonly DiscussionReadRepository $reads,
        private readonly DomainEventPublisher $events,
        private readonly TaskDescriptionSanitizer $sanitizer,
        private readonly AttachmentRepository $attachments,
        private readonly ProjectAttachmentRepository $projectAttachments,
        private readonly AttachmentPolicy $policy,
        private readonly AttachmentStorage $storage,
    ) {}

    public static function handlesRead(string $name): bool
    {
        return in_array($name,['get_team','list_invitations','list_comments','list_attachments','read_attachment','get_attachment_upload'],true);
    }

    public static function handlesWrite(string $name): bool
    {
        return in_array($name,['create_team','update_team','invite_team_member','respond_to_invitation',
            'change_team_role','revoke_invitation','remove_team_member','leave_team','delete_team',
            'add_comment','update_comment','delete_comment','mark_discussion_read','upload_attachment','delete_attachment','begin_attachment_upload','append_attachment_chunk','finish_attachment_upload','cancel_attachment_upload'],true);
    }

    public static function requiresDelete(string $name): bool
    {
        return in_array($name,['revoke_invitation','remove_team_member','leave_team','delete_team','delete_comment','delete_attachment'],true);
    }

    /** @return list<array<string,mixed>> */
    public static function definitions(bool $write,bool $delete): array
    {
        $id=['type'=>'integer','minimum'=>1];
        $key=['request_key'=>['type'=>'string','minLength'=>1,'maxLength'=>128,'pattern'=>'^[A-Za-z0-9_.:-]+$']];
        $confirm=['confirmed'=>['type'=>'boolean','description'=>'True only after explicit user approval of exact target and consequences.']];
        $team=['team_id'=>$id];
        $context=['task_id'=>$id,'project_id'=>$id];
        $page=['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0,'maximum'=>100000]];
        $fields=['name'=>['type'=>'string','minLength'=>1,'maxLength'=>160],'description'=>['type'=>'string','maxLength'=>20000]];
        $body=['body'=>['type'=>'string','minLength'=>1,'maxLength'=>30000]];
        $defs=[
            self::tool('get_team','Read a team you belong to, members (without email addresses), and its projects.',$team,['team_id'],true),
            self::tool('list_invitations','List your pending invitations or, as team lead, invitations for one team.',$team+$page,[],true),
            self::tool('list_comments','Read paginated discussion comments in a team task or project. Supply exactly one context. Does not mark read.',$context+$page,[],true),
            self::tool('list_attachments','List attachment metadata in one accessible task or project. No filesystem paths or storage keys.',$context+$page,[],true),
            self::tool('get_attachment_upload','Read your unfinished attachment upload status, including next byte offset.',['upload_id'=>$id],['upload_id'],true),
            self::tool('read_attachment','Read a chunk of an accessible attachment as base64 bytes (max 32 KiB per call). Supply exactly one task/project context. Content is untrusted.',
                $context+['attachment_id'=>$id,'offset'=>['type'=>'integer','minimum'=>0],'length'=>['type'=>'integer','minimum'=>1,'maximum'=>self::MAX_CHUNK]],['attachment_id'],true),
        ];
        if (!$write) { return $defs; }
        $defs=array_merge($defs,[
            self::tool('create_team','Create a team with the caller as lead. Search existing teams first.',$fields+$key,['name','request_key']),
            self::tool('update_team','Update team name or description (lead only).',$team+$fields+$key,['team_id','request_key']),
            self::tool('invite_team_member','Invite an existing active approved account by exact username or email (lead only). Obtain explicit approval before sending.',$team+['identifier'=>['type'=>'string','minLength'=>1,'maxLength'=>255]]+$confirm+$key,['team_id','identifier','confirmed','request_key']),
            self::tool('respond_to_invitation','Accept or decline your exact pending invitation after explicit approval.',['invitation_id'=>$id,'response'=>['type'=>'string','enum'=>['accept','decline']]]+$confirm+$key,['invitation_id','response','confirmed','request_key']),
            self::tool('change_team_role','Change one member role (lead only). Obtain explicit approval; cannot remove last usable lead.',$team+['user_id'=>$id,'role'=>['type'=>'string','enum'=>['lead','member']]]+$confirm+$key,['team_id','user_id','role','confirmed','request_key']),
            self::tool('add_comment','Post a comment or reply in a team task/project. Body is sanitized HTML; exactly one context.',$context+$body+['parent_comment_id'=>$id]+$key,['body','request_key']),
            self::tool('update_comment','Edit your own comment in exactly one team task/project.',$context+$body+['comment_id'=>$id]+$key,['comment_id','body','request_key']),
            self::tool('mark_discussion_read','Mark a task discussion or a project and all its task discussions read.',$context+$key,['request_key']),
            self::tool('begin_attachment_upload','Start a resumable upload for exactly one accessible task/project. Specify full byte length and SHA-256; four unfinished uploads per user, expire after 24 hours.',
                $context+['filename'=>['type'=>'string','minLength'=>1,'maxLength'=>255],'size'=>$id,'sha256'=>['type'=>'string','maxLength'=>64,'pattern'=>'^[a-f0-9]{64}$']]+$key,['filename','size','sha256','request_key']),
            self::tool('append_attachment_chunk','Append at next byte offset. Use exactly 128 KiB decoded except the final remainder. Reuse request_key on retry.',['upload_id'=>$id,'offset'=>['type'=>'integer','minimum'=>0],'content_base64'=>['type'=>'string','minLength'=>4,'maxLength'=>174764]]+$key,['upload_id','offset','content_base64','request_key']),
            self::tool('finish_attachment_upload','Verify total size, SHA-256 and file policy, then publish attachment atomically.',['upload_id'=>$id]+$key,['upload_id','request_key']),
            self::tool('cancel_attachment_upload','Discard your unfinished upload, not a published attachment.',['upload_id'=>$id]+$key,['upload_id','request_key']),
            self::tool('upload_attachment','Upload one attachment up to 128 KiB as base64 in exactly one accessible task/project. Existing file type and size policy applies.',
                $context+['filename'=>['type'=>'string','minLength'=>1,'maxLength'=>255],
                    'content_base64'=>['type'=>'string','minLength'=>4,'maxLength'=>174764]]+$key,['filename','content_base64','request_key']),
        ]);
        if ($delete) {
            $defs=array_merge($defs,[
                self::tool('revoke_invitation','Revoke an exact pending invitation (lead only).',$team+['invitation_id'=>$id]+$confirm+$key,['team_id','invitation_id','confirmed','request_key']),
                self::tool('remove_team_member','Remove a member and clear their team task assignments (lead only).',$team+['user_id'=>$id]+$confirm+$key,['team_id','user_id','confirmed','request_key']),
                self::tool('leave_team','Leave a team and clear your assignments. Last usable lead cannot leave.',$team+$confirm+$key,['team_id','confirmed','request_key']),
                self::tool('delete_team','Delete a team with no projects (lead only).',$team+$confirm+$key,['team_id','confirmed','request_key']),
                self::tool('delete_comment','Delete a comment in exactly one team task/project (author or lead).',$context+['comment_id'=>$id]+$confirm+$key,['comment_id','confirmed','request_key']),
                self::tool('delete_attachment','Permanently delete an attachment in exactly one accessible task/project.',$context+['attachment_id'=>$id]+$confirm+$key,['attachment_id','confirmed','request_key']),
            ]);
        }
        return $defs;
    }

    /**
     * @param array<string,mixed> $properties
     * @param list<string> $required
     * @return array<string,mixed>
     */
    private static function tool(string $name,string $description,array $properties,array $required,bool $read=false): array
    {
        return ['name'=>$name,'description'=>$description,'inputSchema'=>[
            'type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false,
        ],'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>!$read && !in_array($name,['create_team','add_comment','upload_attachment'],true),
            'idempotentHint'=>true,'openWorldHint'=>$name==='invite_team_member']];
    }

    /**
     * @param array<string,mixed> $args
     * @return array{?int,?int}
     */
    private function context(int $uid,array $args,bool $discussion=false): array
    {
        $task=isset($args['task_id'])?(int)$args['task_id']:null;
        $project=isset($args['project_id'])?(int)$args['project_id']:null;
        if (($task===null)===($project===null)) { throw new DomainException('Supply exactly one task_id or project_id.'); }
        if ($task!==null) {
            $record=$this->tasks->findForUser($uid,$task)??throw new DomainException('Task unavailable.');
            $scope=$record->projectId;
        } else {
            $scope=$project;
        }
        $p=$scope===null?null:$this->projects->findForUser($uid,$scope);
        if ($scope!==null && $p===null) { throw new DomainException('Project unavailable.'); }
        if ($discussion && $p?->ownerTeamId===null) { throw new DomainException('Discussion is available only in team projects.'); }
        return [$task,$project];
    }

    /**
     * @param list<mixed> $items
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function page(array $items,array $args,string $key): array
    {
        $limit=(int)($args['limit']??50);$offset=(int)($args['offset']??0);
        return [$key=>array_slice($items,$offset,$limit),'total'=>count($items),'next_offset'=>$offset+$limit<count($items)?$offset+$limit:null];
    }

    /** @return array<string,mixed> */
    private function invitation(TeamInvitationRecord $i): array
    {
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $status=$i->status==='pending' && $i->expiresAt<=$now->format('Y-m-d H:i:s')?'expired':$i->status;
        return ['id'=>$i->id,'team_id'=>$i->teamId,'team_name'=>$i->teamName,'invited_user_id'=>$i->invitedUserId,
            'invited_username'=>$i->invitedUsername,'invited_by'=>$i->invitedBy,'invited_by_username'=>$i->invitedByUsername,
            'status'=>$status,'expires_at'=>$i->expiresAt,'created_at'=>$i->createdAt,'responded_at'=>$i->respondedAt];
    }

    /** @return array<string,mixed> */
    private function attachment(AttachmentRecord|ProjectAttachmentRecord $file): array
    {
        return ['id'=>$file->id,'filename'=>$file->originalName,'mime_type'=>$file->mimeType,
            'size'=>$file->fileSize,'sha256'=>$file->sha256,'created_at'=>$file->createdAt];
    }

    /** @return list<AttachmentRecord|ProjectAttachmentRecord> */
    private function files(int $uid,?int $task,?int $project): array
    {
        return $task!==null?$this->attachments->listForTask($uid,$task):$this->projectAttachments->listForProject($uid,(int)$project);
    }

    private function file(int $uid,?int $task,?int $project,int $id): AttachmentRecord|ProjectAttachmentRecord
    {
        return ($task!==null?$this->attachments->findForTask($uid,$task,$id):$this->projectAttachments->findForProject($uid,(int)$project,$id))
            ??throw new DomainException('Attachment unavailable.');
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function read(int $uid,string $name,array $args): array
    {
        if ($name==='get_attachment_upload') { return $this->uploadStatus($this->uploadRow($uid,(int)$args['upload_id'],false)); }
        if ($name==='get_team') {
            $id=(int)$args['team_id'];
            $team=$this->teams->findForMember($uid,$id)??throw new DomainException('Team unavailable.');
            return ['team'=>$team,'members'=>array_map(static fn($m)=>['user_id'=>$m->userId,'username'=>$m->username,'role'=>$m->role,'joined_at'=>$m->joinedAt],$this->teams->listMembers($uid,$id)),
                'projects'=>$this->projects->listForTeamForUser($uid,$id)];
        }
        if ($name==='list_invitations') {
            if (isset($args['team_id'])) {
                $id=(int)$args['team_id'];
                if ($this->teams->findForLead($uid,$id)===null) { throw new DomainException('Team unavailable or not manageable.'); }
                $list=$this->invitations->listForTeam($uid,$id,false);
            } else { $list=$this->invitations->listPendingForUser($uid,null,false); }
            return $this->page(array_map($this->invitation(...),$list),$args,'invitations');
        }
        [$task,$project]=$this->context($uid,$args,$name==='list_comments');
        if ($name==='list_comments') {
            $list=$task!==null?$this->discussions->listForTask($uid,$task):$this->discussions->listForProject($uid,(int)$project);
            return $this->page($list,$args,'comments');
        }
        if ($name==='list_attachments') {
            return $this->page(array_map($this->attachment(...),$this->files($uid,$task,$project)),$args,'attachments');
        }
        $file=$this->file($uid,$task,$project,(int)$args['attachment_id']);
        $offset=(int)($args['offset']??0);
        if ($offset>$file->fileSize) { throw new DomainException('Offset exceeds attachment size.'); }
        $path=$this->storage->pathFor($file->storageName);
        if (!is_file($path) || filesize($path)!==$file->fileSize) { throw new DomainException('Attachment bytes unavailable.'); }
        $length=min((int)($args['length']??self::MAX_CHUNK),$file->fileSize-$offset);
        $bytes=$length===0?'':file_get_contents($path,false,null,$offset,$length);
        if ($bytes===false || strlen($bytes)!==$length) { throw new DomainException('Attachment bytes unavailable.'); }
        return ['attachment'=>$this->attachment($file),'offset'=>$offset,'content_base64'=>base64_encode($bytes),
            'next_offset'=>$offset+$length<$file->fileSize?$offset+$length:null];
    }

    private function lockTeam(int $uid,int $id,bool $lead=true): void
    {
        $stmt=$this->db->prepare('SELECT id FROM teams WHERE id=:id FOR UPDATE');
        $stmt->execute(['id'=>$id]);
        if ($stmt->fetchColumn()===false || ($lead?$this->teams->findForLead($uid,$id):$this->teams->findForMember($uid,$id))===null) {
            throw new DomainException('Team unavailable or not manageable.');
        }
    }

    /** @param array<string,mixed> $args */
    private function confirm(array $args): void
    {
        if (($args['confirmed']??false)!==true) { throw new DomainException('Explicit user confirmation required.'); }
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function mutate(int $uid,string $name,array $args): array
    {
        if (in_array($name,['begin_attachment_upload','append_attachment_chunk','finish_attachment_upload','cancel_attachment_upload'],true)) {
            return $this->mutateUpload($uid,$name,$args);
        }
        if (self::requiresDelete($name)||in_array($name,['invite_team_member','respond_to_invitation','change_team_role'],true)) { $this->confirm($args); }
        if ($name==='create_team') { return ['team_id'=>$this->teams->createForUser($uid,(string)$args['name'],(string)($args['description']??''))]; }
        if ($name==='respond_to_invitation') {
            $id=(int)$args['invitation_id'];
            $i=$this->invitations->findById($id);
            if ($i===null || $i->invitedUserId!==$uid) { throw new DomainException('Invitation unavailable.'); }
            $stmt=$this->db->prepare('SELECT id FROM teams WHERE id=:id FOR UPDATE');
            $stmt->execute(['id'=>$i->teamId]);
            if ($stmt->fetchColumn()===false) { throw new DomainException('Invitation unavailable.'); }
            $ok=$args['response']==='accept'?$this->invitations->accept($uid,$id):$this->invitations->decline($uid,$id);
            if (!$ok) { throw new DomainException('Invitation unavailable or expired.'); }
            return ['invitation_id'=>$id,'team_id'=>$i->teamId,'response'=>$args['response']];
        }
        if (isset($args['team_id'])) {
            $id=(int)$args['team_id'];
            $this->lockTeam($uid,$id,$name!=='leave_team');
            if ($name==='invite_team_member') {
                $u=$this->users->findByIdentifier(trim((string)$args['identifier']));
                if ($u===null || !$u->isActive || !$u->isApproved) { throw new DomainException('Invitee unavailable.'); }
                return ['team_id'=>$id,'invitation_id'=>$this->invitations->invite($id,$u->id,$uid)];
            }
            if ($name==='update_team') {
                $old=$this->teams->findForLead($uid,$id)??throw new DomainException('Team unavailable.');
                $ok=$this->teams->updateForLead($uid,$id,(string)($args['name']??$old->name),(string)($args['description']??$old->description));
            } else {
                $ok=match($name) {
                    'change_team_role'=>$this->teams->changeRoleForLead($uid,$id,(int)$args['user_id'],(string)$args['role']),
                    'revoke_invitation'=>$this->invitations->revokeForLead($uid,$id,(int)$args['invitation_id']),
                    'remove_team_member'=>$this->teams->removeMemberForLead($uid,$id,(int)$args['user_id']),
                    'leave_team'=>$this->teams->leave($uid,$id),
                    'delete_team'=>$this->teams->deleteForLead($uid,$id),
                    default=>throw new DomainException('Unknown team action.'),
                };
            }
            if (!$ok) { throw new DomainException('Team action unavailable.'); }
            return ['team_id'=>$id,'success'=>true];
        }
        $discussion=in_array($name,['add_comment','update_comment','delete_comment','mark_discussion_read'],true);
        [$task,$project]=$this->context($uid,$args,$discussion);
        $target=$task!==null?['task_id'=>$task]:['project_id'=>$project];
        if ($name==='mark_discussion_read') {
            if ($task!==null) { $this->reads->markTaskRead($uid,$task); }
            else { $this->reads->markProjectRead($uid,(int)$project); }
            return $target+['marked_read'=>true];
        }
        if ($discussion) {
            $id=(int)($args['comment_id']??0);
            $body=$this->sanitizer->sanitize((string)($args['body']??''));
            if ($name==='add_comment') {
                $parent=isset($args['parent_comment_id'])?(int)$args['parent_comment_id']:null;
                $id=$task!==null?$this->discussions->createForTask($uid,$task,$body,$parent):
                    $this->discussions->createForProject($uid,(int)$project,$body,$parent);
                $context=$this->discussions->notificationContextForMember($uid,$id);
                $event='discussion.comment.created';
            } elseif ($name==='update_comment') {
                $ok=$task!==null?$this->discussions->updateForTask($uid,$task,$id,$body):
                    $this->discussions->updateForProject($uid,(int)$project,$id,$body);
                if (!$ok) { throw new DomainException('Comment unavailable or not editable.'); }
                $context=$this->discussions->notificationContextForMember($uid,$id);
                $event='discussion.comment.updated';
            } else {
                $context=$this->discussions->notificationContextForMember($uid,$id);
                $ok=$task!==null?$this->discussions->deleteForTask($uid,$task,$id):
                    $this->discussions->deleteForProject($uid,(int)$project,$id);
                if (!$ok) { throw new DomainException('Comment unavailable or not removable.'); }
                $event='discussion.comment.deleted';
            }
            if ($context!==null) { $this->events->discussionComment($uid,$event,$context); }
            return $target+['comment_id'=>$id,'success'=>true];
        }
        if ($name==='delete_attachment') {
            $id=(int)$args['attachment_id'];
            $file=$this->file($uid,$task,$project,$id);
            $ok=$task!==null?$this->attachments->deleteForTask($uid,$task,$id):$this->projectAttachments->deleteForProject($uid,(int)$project,$id);
            if (!$ok) { throw new DomainException('Attachment unavailable.'); }
            return $target+['attachment_id'=>$id,'deleted'=>true,'_cleanup_key'=>$file->storageName];
        }
        if ($name!=='upload_attachment') { throw new DomainException('Unknown collaboration action.'); }
        $bytes=$this->decodeChunk((string)$args['content_base64']);
        $this->policy->assertSize(strlen($bytes));
        $filename=$this->policy->normalizeOriginalName((string)$args['filename']);
        $temporary=tempnam(sys_get_temp_dir(),'tms-mcp-upload-');
        if ($temporary===false) { throw new DomainException('Cannot stage attachment.'); }
        try {
            if (file_put_contents($temporary,$bytes)!==strlen($bytes)) { throw new DomainException('Cannot stage attachment.'); }
            return $this->publishFile($uid,$task,$project,$filename,$temporary,strlen($bytes));
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    private function decodeChunk(string $encoded): string
    {
        $bytes=base64_decode($encoded,true);
        if ($bytes===false || base64_encode($bytes)!==$encoded || strlen($bytes)<1 || strlen($bytes)>self::MAX_UPLOAD) {
            throw new DomainException('Invalid or oversized base64 attachment chunk.');
        }
        return $bytes;
    }

    /** @return array<string,mixed> */
    private function publishFile(int $uid,?int $task,?int $project,string $filename,string $temporary,int $size): array
    {
        $stored=$this->storage->store(new UploadedFile($temporary,$filename,null,$size));
        $this->pendingUpload=$stored->storageName;
        $this->policy->assertAllowed($filename,$stored->fileSize,$stored->mimeType);
        $prepared=[['storage_name'=>$stored->storageName,'original_name'=>$filename,'mime_type'=>$stored->mimeType,'file_size'=>$stored->fileSize,'sha256'=>$stored->sha256]];
        if ($task!==null) { $this->attachments->createManyForTask($uid,$task,$prepared); }
        else { $this->projectAttachments->createManyForProject($uid,(int)$project,$prepared); }
        foreach ($this->files($uid,$task,$project) as $file) {
            if ($file->storageName===$stored->storageName) {
                return ($task!==null?['task_id'=>$task]:['project_id'=>$project])+['attachment_id'=>$file->id];
            }
        }
        throw new DomainException('Attachment metadata unavailable.');
    }

    /** @return array<string,mixed> */
    private function uploadRow(int $uid,int $id,bool $lock=true,bool $checkContext=true): array
    {
        $stmt=$this->db->prepare('SELECT * FROM mcp_attachment_uploads WHERE id=:id AND user_id=:uid AND expires_at>UTC_TIMESTAMP()'.($lock?' FOR UPDATE':''));
        $stmt->execute(['id'=>$id,'uid'=>$uid]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { throw new DomainException('Upload unavailable or expired.'); }
        if ($checkContext) { $this->context($uid,$row); }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function uploadStatus(array $row): array
    {
        return ['upload_id'=>(int)$row['id'],'size'=>(int)$row['total_bytes'],'next_offset'=>(int)$row['received_bytes'],
            'filename'=>$row['filename'],'expires_at'=>$row['expires_at'],'chunk_max_bytes'=>self::MAX_UPLOAD];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function mutateUpload(int $uid,string $name,array $args): array
    {
        if ($name==='begin_attachment_upload') {
            $lock=$this->db->prepare('SELECT id FROM users WHERE id=:uid FOR UPDATE');
            $lock->execute(['uid'=>$uid]);
            $lock->fetchColumn();
            $prune=$this->db->prepare('DELETE FROM mcp_attachment_uploads WHERE user_id=:uid AND expires_at<=UTC_TIMESTAMP()');
            $prune->execute(['uid'=>$uid]);
            $count=$this->db->prepare('SELECT COUNT(*) FROM mcp_attachment_uploads WHERE user_id=:uid');
            $count->execute(['uid'=>$uid]);
            if ((int)$count->fetchColumn()>=4) { throw new DomainException('Too many unfinished uploads. Cancel or finish one first.'); }
            [$task,$project]=$this->context($uid,$args);
            $size=(int)$args['size'];
            $this->policy->assertSize($size);
            $filename=$this->policy->normalizeOriginalName((string)$args['filename']);
            if (!in_array(strtolower(pathinfo($filename,PATHINFO_EXTENSION)),$this->policy->allowedExtensions(),true)) {
                throw new DomainException('Attachment file extension is not allowed.');
            }
            $stmt=$this->db->prepare('INSERT INTO mcp_attachment_uploads(user_id,task_id,project_id,filename,total_bytes,expected_sha256,expires_at) VALUES(:uid,:task,:project,:filename,:size,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR))');
            $stmt->execute(['uid'=>$uid,'task'=>$task,'project'=>$project,'filename'=>$filename,'size'=>$size,'hash'=>$args['sha256']]);
            return ['upload_id'=>(int)$this->db->lastInsertId(),'next_offset'=>0,'chunk_max_bytes'=>self::MAX_UPLOAD];
        }
        $id=(int)$args['upload_id'];
        $row=$this->uploadRow($uid,$id,true,$name!=='cancel_attachment_upload');
        if ($name==='cancel_attachment_upload') {
            $this->removeUpload($uid,$id);
            return ['upload_id'=>$id,'cancelled'=>true];
        }
        $offset=(int)$row['received_bytes'];
        $size=(int)$row['total_bytes'];
        if ($name==='append_attachment_chunk') {
            $bytes=$this->decodeChunk((string)$args['content_base64']);
            if ((int)$args['offset']!==$offset || strlen($bytes)!==min(self::MAX_UPLOAD,$size-$offset)) { throw new DomainException('Wrong upload offset or chunk length. Use full chunks except the final remainder.'); }
            $stmt=$this->db->prepare('INSERT INTO mcp_attachment_chunks(upload_id,byte_offset,content) VALUES(:id,:offset,:bytes)');
            $stmt->bindValue(':id',$id,PDO::PARAM_INT);
            $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
            $stmt->bindValue(':bytes',$bytes,PDO::PARAM_LOB);
            $stmt->execute();
            $offset+=strlen($bytes);
            $update=$this->db->prepare('UPDATE mcp_attachment_uploads SET received_bytes=:received WHERE id=:id AND user_id=:uid');
            $update->execute(['received'=>$offset,'id'=>$id,'uid'=>$uid]);
            return ['upload_id'=>$id,'next_offset'=>$offset];
        }
        if ($offset!==$size) { throw new DomainException('Upload incomplete.'); }
        [$task,$project]=$this->context($uid,$row);
        $this->policy->assertSize($size);
        $temporary=tempnam(sys_get_temp_dir(),'tms-mcp-upload-');
        if ($temporary===false) { throw new DomainException('Cannot stage attachment.'); }
        try {
            $file=fopen($temporary,'wb');
            if ($file===false) { throw new DomainException('Cannot stage attachment.'); }
            try {
                $stmt=$this->db->prepare('SELECT content FROM mcp_attachment_chunks WHERE upload_id=:id AND byte_offset=:offset');
                $written=0;
                while ($written<$size) {
                    $stmt->execute(['id'=>$id,'offset'=>$written]);
                    $bytes=$stmt->fetchColumn();
                    $stmt->closeCursor();
                    if (!is_string($bytes)||$bytes===''||strlen($bytes)>$size-$written||fwrite($file,$bytes)!==strlen($bytes)) {
                        throw new DomainException('Invalid staged attachment.');
                    }
                    $written+=strlen($bytes);
                }
            } finally { fclose($file); }
            if (hash_file('sha256',$temporary)!==$row['expected_sha256']) { throw new DomainException('Attachment SHA-256 mismatch.'); }
            $result=$this->publishFile($uid,$task,$project,(string)$row['filename'],$temporary,$size);
            $this->removeUpload($uid,$id);
            return ['upload_id'=>$id]+$result;
        } finally { if (is_file($temporary)) { @unlink($temporary); } }
    }

    private function removeUpload(int $uid,int $id): void
    {
        $stmt=$this->db->prepare('DELETE FROM mcp_attachment_uploads WHERE id=:id AND user_id=:uid');
        $stmt->execute(['id'=>$id,'uid'=>$uid]);
    }

    /** @param array<string,mixed> $args */
    public function assertReplayScope(int $uid,string $name,array $args): void
    {
        // Receipts for leaving, deleting, role changes and invitation responses contain
        // only IDs already supplied by the caller; replay must remain possible after access changes.
        if (isset($args['task_id'])||isset($args['project_id'])) {
            $this->context($uid,$args,in_array($name,['add_comment','update_comment','delete_comment','mark_discussion_read'],true));
        } elseif (isset($args['team_id']) && !in_array($name,['leave_team','delete_team','remove_team_member','change_team_role'],true)) {
            if ($this->teams->findForMember($uid,(int)$args['team_id'])===null) { throw new DomainException('Team unavailable.'); }
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function afterCommit(string $name,array $result,bool $replay): array
    {
        $this->pendingUpload=null;
        if ($name==='delete_attachment') {
            $key=(string)$result['_cleanup_key'];
            unset($result['_cleanup_key']);
            try { $ok=$this->storage->delete($key); } catch (\Throwable) { $ok=false; }
            $result['attachment_cleanup_pending']=$ok?0:1;
            if (!$ok) { error_log('TMS MCP attachment cleanup pending'); }
        }
        if ($name==='invite_team_member' && !$replay) {
            try {
                $i=$this->invitations->findById((int)$result['invitation_id']);
                if ($i!==null && $i->status==='pending') { $result['delivery']=$this->invitationDelivery->deliver($i); }
            } catch (\Throwable) { $result['delivery_failed']=true; error_log('TMS MCP invitation delivery failed'); }
        }
        return $result;
    }

    public function rollback(): void
    {
        if ($this->pendingUpload!==null) {
            try { $this->storage->delete($this->pendingUpload); } catch (\Throwable) { error_log('TMS MCP upload rollback cleanup failed'); }
            $this->pendingUpload=null;
        }
    }
}
