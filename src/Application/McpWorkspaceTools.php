<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use DomainException;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Project\ProjectAttachmentRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\SavedView\SavedViewQuery;
use Tms\Domain\SavedView\SavedViewRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\UserPreference\UserPreferenceRepository;
use Tms\Infrastructure\AttachmentStorage;

final class McpWorkspaceTools
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly ProjectAttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
        private readonly DomainEventPublisher $events,
        private readonly SavedViewRepository $views,
        private readonly SavedViewQuery $viewQuery,
        private readonly InternalNotificationRepository $notifications,
        private readonly NotificationSettingsRepository $notificationSettings,
        private readonly UserPreferenceRepository $preferences,
        private readonly UserBootstrapService $bootstrap,
        private readonly string $timezone,
    ) {}

    public static function handlesRead(string $name): bool
    {
        return in_array($name,['list_calendar','list_saved_views','get_saved_view','list_notifications','get_preferences','get_notification_settings'],true);
    }

    public static function handlesWrite(string $name): bool
    {
        return in_array($name,['create_saved_view','set_default_saved_view','delete_saved_view','mark_notification_read','mark_all_notifications_read',
            'update_preferences','update_notification_settings','restore_default_statuses','transfer_project','delete_project'],true);
    }

    public static function requiresDelete(string $name): bool
    {
        return in_array($name,['delete_saved_view','delete_project'],true);
    }

    /** @return array<string,mixed> */
    private static function notificationFields(): array
    {
        $fields=[];
        foreach (['email_enabled','telegram_enabled','notify_task_assignments','notify_task_dates','notify_task_status','notify_tomorrow','notify_upcoming','notify_overdue','notify_digest'] as $key) {
            $fields[$key]=['type'=>'boolean'];
        }
        foreach (['tomorrow_time','overdue_time','digest_time'] as $key) {
            $fields[$key]=['type'=>'string','maxLength'=>5,'pattern'=>'^(?:[01][0-9]|2[0-3]):[0-5][0-9]$'];
        }
        foreach (['urgent_minutes','high_minutes','medium_minutes','low_minutes'] as $key) {
            $fields[$key]=['type'=>'integer','minimum'=>1,'maximum'=>10080];
        }
        return $fields;
    }

    /** @return list<array<string,mixed>> */
    public static function definitions(bool $write,bool $delete): array
    {
        $id=['type'=>'integer','minimum'=>1];
        $bool=['type'=>'boolean'];
        $key=['request_key'=>['type'=>'string','minLength'=>1,'maxLength'=>128,'pattern'=>'^[A-Za-z0-9_.:-]+$']];
        $confirmed=['confirmed'=>['type'=>'boolean','description'=>'True only after explicit user approval of this exact action and consequences.']];
        $day=['type'=>'string','maxLength'=>10,'pattern'=>'^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
        $ids=['type'=>'array','maxItems'=>100,'items'=>$id];
        $page=['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0,'maximum'=>100000]];
        $defs=[
            self::tool('list_calendar','List accessible tasks in an inclusive calendar date range (max 366 days), including planned, deadline or undated modes. Uses server task date storage timezone.',
                ['from'=>$day,'to'=>$day,'mode'=>['type'=>'string','enum'=>['planned_only','deadlines_only','no_dates','all']],
                 'project_id'=>$id,'without_project'=>$bool,'status_ids'=>$ids,'type_ids'=>$ids,
                 'priorities'=>['type'=>'array','maxItems'=>4,'items'=>['type'=>'integer','minimum'=>0,'maximum'=>3]],
                 'status_invert'=>$bool,'type_invert'=>$bool,'priority_invert'=>$bool,'customer_id'=>$id,
                 'customer_query'=>['type'=>'string','maxLength'=>255]]+$page,['from','to'],true),
            self::tool('list_saved_views','List your saved task-list views.',[],[],true),
            self::tool('get_saved_view','Read a saved view including its normalized web query parameters.',['view_id'=>$id],['view_id'],true),
            self::tool('list_notifications','Read your internal notification inbox (up to 200 latest items) and unread count. Does not mark read.',
                ['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>200]],[],true),
            self::tool('get_preferences','Read your timezone and theme.',[],[],true),
            self::tool('get_notification_settings','Read notification schedule and enabled channels; no destination addresses or identifiers.',[],[],true),
        ];
        if (!$write) { return $defs; }
        $defs=array_merge($defs,[
            self::tool('create_saved_view','Create a saved task-list view using the same URL query string as the web UI (without leading ?). Unknown keys are discarded.',
                ['name'=>['type'=>'string','minLength'=>1,'maxLength'=>96],'query'=>['type'=>'string','maxLength'=>16000],'is_default'=>$bool]+$key,['name','query','request_key']),
            self::tool('set_default_saved_view','Set one owned view as default.',['view_id'=>$id]+$key,['view_id','request_key']),
            self::tool('mark_notification_read','Mark one of your notifications read.',['notification_id'=>$id]+$key,['notification_id','request_key']),
            self::tool('mark_all_notifications_read','Mark all your current internal notifications read.',$confirmed+$key,['confirmed','request_key']),
            self::tool('update_preferences','Change your timezone or theme. Explicit confirmation required.',
                ['timezone'=>['type'=>'string','maxLength'=>128],'theme'=>['type'=>'string','enum'=>UserPreferenceRepository::THEMES]]+$confirmed+$key,['confirmed','request_key']),
            self::tool('update_notification_settings','Patch your existing notification schedules/channel switches; cannot change recipient addresses or account links. Obtain explicit approval.',
                self::notificationFields()+$confirmed+$key,['confirmed','request_key']),
            self::tool('restore_default_statuses','Restore missing personal default status templates without overwriting existing roles.',$key,['request_key']),
            self::tool('transfer_project','Transfer project ownership to a team you lead, or null to your personal ownership. This changes access, clears task assignees and clears personal metadata when moving to a team. Confirm exact project and destination.',
                ['project_id'=>$id,'team_id'=>['type'=>['integer','null'],'minimum'=>1]]+$confirmed+$key,['project_id','team_id','confirmed','request_key']),
        ]);
        if ($delete) {
            $defs[]=self::tool('delete_saved_view','Delete an exact saved view after explicit approval.',['view_id'=>$id]+$confirmed+$key,['view_id','confirmed','request_key']);
            $defs[]=self::tool('delete_project','Permanently delete an empty manageable project and its project attachments/discussions/definitions. Projects containing tasks must first be emptied in separate explicit operations. Confirm exact project ID.',
                ['project_id'=>$id]+$confirmed+$key,['project_id','confirmed','request_key']);
        }
        return $defs;
    }

    /**
     * @param array<string,mixed> $props
     * @param list<string> $required
     * @return array<string,mixed>
     */
    private static function tool(string $name,string $description,array $props,array $required,bool $read=false): array
    {
        return ['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>(object)$props,'required'=>$required,'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>!$read&&!in_array($name,['create_saved_view','restore_default_statuses'],true),'idempotentHint'=>true,'openWorldHint'=>false]];
    }

    /** @return array<string,mixed> */
    private function notificationValues(int $uid): array
    {
        $s=$this->notificationSettings->getForUser($uid);
        $all=get_object_vars($s);
        $values=[];
        foreach (array_keys(self::notificationFields()) as $key) {
            $property=lcfirst(str_replace(' ','',ucwords(str_replace('_',' ',$key))));
            $values[$key]=str_ends_with($key,'_time')?substr((string)$all[$property],0,5):$all[$property];
        }
        return $values;
    }

    private function day(string $s): DateTimeImmutable
    {
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
        if ($day===false||$day->format('Y-m-d')!==$s) { throw new DomainException('Invalid calendar date.'); }
        return $day;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function read(int $uid,string $name,array $args): array
    {
        if ($name==='list_saved_views') { return ['views'=>$this->views->listForUser($uid)]; }
        if ($name==='get_saved_view') { return ['view'=>$this->views->findForUser($uid,(int)$args['view_id'])??throw new DomainException('View unavailable.')]; }
        if ($name==='list_notifications') { return ['notifications'=>$this->notifications->listForUser($uid,(int)($args['limit']??100)),'unread'=>$this->notifications->countUnreadForUser($uid)]; }
        if ($name==='get_preferences') { return ['preferences'=>$this->preferences->getForUser($uid)]; }
        if ($name==='get_notification_settings') { return ['settings'=>$this->notificationValues($uid)]; }
        $from=$this->day((string)$args['from']);
        $to=$this->day((string)$args['to']);
        if ($to<$from||$from->diff($to)->days>365) { throw new DomainException('Calendar range must be 1–366 days.'); }
        $project=isset($args['project_id'])?(int)$args['project_id']:null;
        $none=($args['without_project']??false)===true;
        if ($project!==null&&($none||$this->projects->findForUser($uid,$project)===null)) { throw new DomainException('Project unavailable or conflicting scope.'); }
        $tasks=$this->tasks->listCalendarForUser($uid,$from->format('Y-m-d H:i:s'),$to->modify('+1 day')->format('Y-m-d H:i:s'),
            mode:(string)($args['mode']??'all'),statusIds:$args['status_ids']??[],typeIds:$args['type_ids']??[],
            customerId:isset($args['customer_id'])?(int)$args['customer_id']:null,priorities:$args['priorities']??[],
            statusInvert:($args['status_invert']??false)===true,typeInvert:($args['type_invert']??false)===true,
            priorityInvert:($args['priority_invert']??false)===true,customerQuery:(string)($args['customer_query']??''),projectId:$project,withoutProject:$none);
        $offset=(int)($args['offset']??0);$limit=(int)($args['limit']??50);
        return ['tasks'=>array_slice($tasks,$offset,$limit),'total'=>count($tasks),'next_offset'=>$offset+$limit<count($tasks)?$offset+$limit:null,'timezone'=>$this->timezone];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function mutate(int $uid,string $name,array $args): array
    {
        if (self::requiresDelete($name)||in_array($name,['transfer_project','update_preferences','update_notification_settings','mark_all_notifications_read'],true)) {
            if (($args['confirmed']??false)!==true) { throw new DomainException('Explicit user confirmation required.'); }
        }
        if ($name==='create_saved_view') {
            parse_str((string)$args['query'],$raw);
            $query=$this->viewQuery->normalize($raw);
            if (isset($query['project'])&&ctype_digit((string)$query['project'])&&$this->projects->findForUser($uid,(int)$query['project'])===null) {
                throw new DomainException('View project unavailable.');
            }
            return ['view_id'=>$this->views->create($uid,(string)$args['name'],$query,($args['is_default']??false)===true)];
        }
        if ($name==='set_default_saved_view'||$name==='delete_saved_view') {
            $id=(int)$args['view_id'];
            $ok=$name==='set_default_saved_view'?$this->views->setDefault($uid,$id):$this->views->delete($uid,$id);
            if (!$ok) { throw new DomainException('View unavailable.'); }
            return ['view_id'=>$id,'success'=>true];
        }
        if ($name==='mark_notification_read') {
            $id=(int)$args['notification_id'];
            if ($this->notifications->findForUser($uid,$id)===null) { throw new DomainException('Notification unavailable.'); }
            $this->notifications->markReadForUser($uid,$id);
            return ['notification_id'=>$id,'marked_read'=>true];
        }
        if ($name==='mark_all_notifications_read') {
            $this->notifications->markAllReadForUser($uid);
            return ['marked_all_read'=>true];
        }
        if ($name==='update_preferences') {
            $old=$this->preferences->getForUser($uid);
            $this->preferences->saveForUser($uid,(string)($args['timezone']??$old->timezone),(string)($args['theme']??$old->theme));
            return ['success'=>true];
        }
        if ($name==='update_notification_settings') {
            $old=$this->notificationSettings->getForUser($uid);
            $values=array_intersect_key($args,self::notificationFields())+$this->notificationValues($uid);
            $values['email_address']=$old->emailAddress;
            $this->notificationSettings->saveForUser($uid,$values);
            return ['success'=>true];
        }
        if ($name==='restore_default_statuses') { return ['created_count'=>$this->bootstrap->restoreMissingStatuses($uid)]; }
        $id=(int)$args['project_id'];
        $before=$this->projects->findManageableForUser($uid,$id,true)??throw new DomainException('Project unavailable or not manageable.');
        if ($name==='transfer_project') {
            $team=$args['team_id']===null?null:(int)$args['team_id'];
            if (!$this->projects->changeOwnershipForUser($uid,$id,$team)) { throw new DomainException('Project unavailable.'); }
            $after=$this->projects->findForUser($uid,$id)??throw new DomainException('Project unavailable.');
            $this->events->projectChanged($uid,$before,$after);
            return ['project_id'=>$id,'team_id'=>$team,'success'=>true];
        }
        if ($name!=='delete_project') { throw new DomainException('Unknown workspace action.'); }
        if ($this->tasks->listFilteredForUser($uid,projectId:$id)!==[]) { throw new DomainException('Project contains tasks. Move them explicitly before deletion.'); }
        $keys=array_map(static fn($file)=>$file->storageName,$this->attachments->listForProject($uid,$id));
        if (!$this->projects->deleteForUser($uid,$id)) { throw new DomainException('Project unavailable.'); }
        $this->events->projectEvent($uid,$before,'project.deleted');
        return ['project_id'=>$id,'deleted'=>true,'_cleanup_keys'=>$keys];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function afterCommit(string $name,array $result): array
    {
        if ($name==='delete_project') {
            $pending=0;
            foreach ($result['_cleanup_keys'] as $key) {
                try { if (!$this->storage->delete((string)$key)) { $pending++; } }
                catch (\Throwable) { $pending++; }
            }
            unset($result['_cleanup_keys']);
            $result['attachment_cleanup_pending']=$pending;
            if ($pending>0) { error_log('TMS MCP project attachment cleanup pending'); }
        }
        return $result;
    }
}
