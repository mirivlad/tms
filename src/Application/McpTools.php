<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Tms\Domain\Checklist\ChecklistRepository;
use Tms\Domain\Attachment\AttachmentRepository;
use Tms\Infrastructure\AttachmentStorage;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\Security\TaskDescriptionSanitizer;

final class McpTools
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ProjectRepository $projects,
        private readonly StatusRepository $statuses,
        private readonly ProjectStatusRepository $projectStatuses,
        private readonly ChecklistRepository $checklists,
        private readonly DomainEventPublisher $events,
        private readonly TaskDescriptionSanitizer $sanitizer,
        private readonly string $timezone,
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public static function definitions(bool $write, bool $delete = false): array
    {
        $id = ['type'=>'integer','minimum'=>1];
        $str = ['type'=>'string','maxLength'=>2000];
        $date = ['type'=>['string','null'],'description'=>'ISO 8601 timestamp with explicit UTC offset, or null to clear.'];
        $key = ['type'=>'string','minLength'=>1,'maxLength'=>128,'pattern'=>'^[A-Za-z0-9_.:-]+$','description'=>'Unique operation key. Reuse the same key when retrying the same mutation.'];
        $fields = ['title'=>['type'=>'string','minLength'=>1,'maxLength'=>255],
            'description'=>['type'=>'string','maxLength'=>30000],
            'deadline'=>$date,'scheduled_at'=>$date,'priority'=>['type'=>'integer','minimum'=>0,'maximum'=>3],
            'status_id'=>$id];
        $defs = [
            self::tool('list_projects','List accessible personal and team projects.', [], [], true),
            self::tool('list_statuses','List statuses for a project, or personal statuses when omitted.', ['project_id'=>$id], [], true),
            self::tool('list_tasks','Find accessible tasks by text, project, status or overdue flag. Returns a page of results.', [
                'query'=>$str,'project_id'=>$id,'status_id'=>$id,'overdue'=>['type'=>'boolean'],
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],
                'offset'=>['type'=>'integer','minimum'=>0,'maximum'=>100000],
            ], [], true),
            self::tool('get_task','Get an accessible task including its checklist.', ['task_id'=>$id], ['task_id'], true),
        ];
        if ($write) {
            $defs[] = self::tool('create_task','Create a task, optionally with checklist items. Search existing tasks first. Dates require an explicit offset.',
                $fields + ['project_id'=>$id,'request_key'=>$key,'checklist'=>['type'=>'array','maxItems'=>50,'items'=>['type'=>'string','minLength'=>1,'maxLength'=>500]]],
                ['title','request_key'], false);
            $defs[] = self::tool('update_task','Update specified fields only; omitted fields remain unchanged.', $fields + ['task_id'=>$id,'request_key'=>$key], ['task_id','request_key'], false);
            $defs[] = self::tool('complete_task','Move a task to its configured completion status.', ['task_id'=>$id,'request_key'=>$key], ['task_id','request_key'], false);
            $defs[] = self::tool('add_checklist_item','Add a checklist item to an accessible task.', ['task_id'=>$id,'text'=>['type'=>'string','minLength'=>1,'maxLength'=>500],'request_key'=>$key], ['task_id','text','request_key'], false);
            $defs[] = self::tool('set_checklist_completed','Set a checklist item completion state.', ['task_id'=>$id,'item_id'=>$id,'completed'=>['type'=>'boolean'],'request_key'=>$key], ['task_id','item_id','completed','request_key'], false);
        }
        if ($write && $delete) {
            $confirm = ['type'=>'boolean','description'=>'Must be true only after explicit user approval of these exact tasks.'];
            $defs[] = self::tool('delete_task','Permanently delete one accessible task and its attachments. Show the task and obtain explicit user approval before calling.',
                ['task_id'=>$id,'confirmed'=>$confirm,'request_key'=>$key], ['task_id','confirmed','request_key'], false);
            $defs[] = self::tool('delete_tasks','Permanently delete 1–100 accessible tasks by explicit IDs, atomically. Show the exact selection and obtain explicit user approval before calling. Never delete by wildcard.',
                ['task_ids'=>['type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>$id],'confirmed'=>$confirm,'request_key'=>$key],
                ['task_ids','confirmed','request_key'], false);
        }
        return $defs;
    }

    /**
     * @param array<string,mixed> $properties
     * @param list<string> $required
     * @return array<string,mixed>
     */
    private static function tool(string $name, string $description, array $properties, array $required, bool $read): array
    {
        return ['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>in_array($name,['update_task','complete_task','set_checklist_completed','delete_task','delete_tasks'],true),'idempotentHint'=>true,'openWorldHint'=>false]];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function read(int $uid, string $name, array $args): array
    {
        $project = $this->optionalInt($args,'project_id');
        if ($project !== null && $this->projects->findForUser($uid,$project) === null) {
            throw new DomainException('Project not found or inaccessible.');
        }
        return match($name) {
            'list_projects'=>['projects'=>$this->projects->listForUser($uid)],
            'list_statuses'=>['statuses'=>$this->scopeStatuses($uid,$project)],
            'get_task'=>$this->detail($uid,$this->requiredInt($args,'task_id')),
            'list_tasks'=>$this->listTasks($uid,$args,$project),
            default=>throw new DomainException('Unknown read tool.'),
        };
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function listTasks(int $uid,array $args,?int $project): array
    {
        $limit = $this->optionalInt($args,'limit') ?? 30;
        $offset = $this->optionalInt($args,'offset') ?? 0;
        $list = $this->tasks->listFilteredForUser($uid,statusId:$this->optionalInt($args,'status_id'),
            query:$this->string($args,'query',''),overdue:($args['overdue'] ?? false) === true,projectId:$project);
        return ['tasks'=>array_slice($list,$offset,$limit),'total'=>count($list),'next_offset'=>$offset+$limit<count($list)?$offset+$limit:null,'timezone'=>$this->timezone];
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(int $uid,int $id): array
    {
        return ['task'=>$this->task($uid,$id),'checklist'=>$this->checklists->listForTask($uid,$id),'timezone'=>$this->timezone];
    }

    /**
     * @param array<string,mixed> $args
     */
    public function mutate(int $uid,string $name,array $args): int
    {
        if ($name === 'create_task') {
            $project = $this->optionalInt($args,'project_id');
            $status = $this->optionalInt($args,'status_id') ?? $this->configuredStatus($uid,$project,false);
            $id = $this->tasks->createForUser($uid,$this->string($args,'title'),$this->description($this->string($args,'description','')),
                $this->date($args['deadline'] ?? null),$status,null,$this->optionalInt($args,'priority') ?? 1,null,$project,null,$this->date($args['scheduled_at'] ?? null));
            $task = $this->task($uid,$id);
            $this->events->taskCreated($uid,$task);
            $items = $args['checklist'] ?? [];
            if (!is_array($items)) { throw new DomainException('Invalid checklist.'); }
            foreach ($items as $item) {
                if (!is_string($item)) { throw new DomainException('Invalid checklist item.'); }
                $this->addItem($uid,$task,$item);
            }
            return $id;
        }
        $id = $this->requiredInt($args,'task_id');
        $before = $this->task($uid,$id);
        switch($name) {
            case 'update_task':
                $status = $this->optionalInt($args,'status_id') ?? $before->statusId ?? $this->configuredStatus($uid,$before->projectId,false);
                if (!$this->tasks->updateForUser($uid,$id,$this->string($args,'title',$before->title),
                    array_key_exists('description',$args)?$this->description($this->string($args,'description')):$before->description,
                    array_key_exists('deadline',$args)?$this->date($args['deadline']):$before->deadline,
                    $status,$before->typeId,$this->optionalInt($args,'priority')??$before->priority,$before->customerId,
                    $before->projectId,$before->assigneeUserId,array_key_exists('scheduled_at',$args)?$this->date($args['scheduled_at']):$before->scheduledAt)) {
                    throw new DomainException('Task unavailable.');
                }
                $this->events->taskChanged($uid,$before,$this->task($uid,$id));
                break;
            case 'complete_task':
                $status = $this->configuredStatus($uid,$before->projectId,true);
                if ($before->statusId !== $status) {
                    if (!$this->tasks->updateStatusForUser($uid,$id,$status)) { throw new DomainException('Task unavailable.'); }
                    $this->events->taskChanged($uid,$before,$this->task($uid,$id));
                }
                break;
            case 'add_checklist_item':
                $this->addItem($uid,$before,$this->string($args,'text'));
                break;
            case 'set_checklist_completed':
                $itemId = $this->requiredInt($args,'item_id');
                $item = $this->checklists->findForTask($uid,$id,$itemId);
                $done = ($args['completed'] ?? false) === true;
                if ($item === null) { throw new DomainException('Checklist item unavailable.'); }
                if ($item->isCompleted !== $done) {
                    if (!$this->checklists->setCompleted($uid,$id,$itemId,$done)) { throw new DomainException('Checklist item unavailable.'); }
                    $this->events->taskEvent($uid,$before,$done?'task.checklist_completed':'task.checklist_reopened',['checklist_item'=>['old'=>null,'new'=>$item->text]]);
                }
                break;
            default: throw new DomainException('Unknown write tool.');
        }
        return $id;
    }

    /**
     * @param array<string,mixed> $args
     * @return list<int>
     */
    public function deletionIds(string $name, array $args): array
    {
        if (($args['confirmed'] ?? false) !== true) {
            throw new DomainException('Explicit deletion confirmation required.');
        }
        $ids = $name === 'delete_task' ? [$this->requiredInt($args,'task_id')] : ($args['task_ids'] ?? []);
        if (!is_array($ids) || $ids === [] || count($ids) > 100) {
            throw new DomainException('Provide 1–100 distinct task IDs.');
        }
        $result = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || isset($result[$id])) {
                throw new DomainException('Provide 1–100 distinct task IDs.');
            }
            $result[$id] = $id;
        }
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /**
     * Caller owns the database transaction. Lock tasks in ID order, validate all
     * before deleting any, and defer irreversible filesystem cleanup until commit.
     * @param list<int> $ids
     * @return list<string>
     */
    public function deleteTasks(int $uid, array $ids): array
    {
        $tasks = [];
        foreach ($ids as $id) {
            $task = $this->tasks->findForUser($uid,$id,true);
            if ($task === null) { throw new DomainException('Task not found or inaccessible; no tasks deleted.'); }
            $tasks[] = $task;
        }
        $keys = [];
        foreach ($tasks as $task) {
            foreach ($this->attachments->listForTask($uid,$task->id) as $attachment) {
                $keys[] = $attachment->storageName;
            }
            if (!$this->tasks->deleteForUser($uid,$task->id)) {
                throw new DomainException('Task unavailable; no tasks deleted.');
            }
            $this->events->taskEvent($uid,$task,'task.deleted');
        }
        return $keys;
    }

    /** @param list<string> $keys */
    public function cleanupDeletedAttachments(array $keys): int
    {
        $failed = 0;
        foreach ($keys as $key) {
            try {
                if ($this->storage->delete($key)) { continue; }
            } catch (\Throwable $e) {
                error_log('TMS MCP attachment cleanup failed: '.get_class($e));
            }
            ++$failed;
        }
        if ($failed > 0) { error_log('TMS MCP pending attachment cleanup: '.$failed); }
        return $failed;
    }

    private function addItem(int $uid,TaskRecord $task,string $text): void
    {
        $id = $this->checklists->createForTask($uid,$task->id,$text);
        $item = $this->checklists->findForTask($uid,$task->id,$id);
        if ($item === null) { throw new DomainException('Checklist item unavailable.'); }
        $this->events->taskEvent($uid,$task,'task.checklist_added',['checklist_item'=>['old'=>null,'new'=>$item->text]]);
    }

    private function task(int $uid,int $id): TaskRecord
    {
        return $this->tasks->findForUser($uid,$id) ?? throw new DomainException('Task not found or inaccessible.');
    }

    /**
     * @return list<\Tms\Domain\Status\StatusRecord>
     */
    private function scopeStatuses(int $uid,?int $project): array
    {
        return $project === null ? $this->statuses->listForUser($uid) : $this->projectStatuses->listForProject($uid,$project);
    }

    private function configuredStatus(int $uid,?int $project,bool $complete): int
    {
        $fallback = null;
        foreach ($this->scopeStatuses($uid,$project) as $status) {
            $fallback ??= $status->id;
            if ($complete ? $status->isCompletion : $status->isDefault) { return $status->id; }
        }
        if (!$complete && $fallback !== null) { return $fallback; }
        throw new DomainException('No configured status for this operation.');
    }

    private function description(string $value): string
    {
        return $this->sanitizer->sanitize(nl2br(htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8'),false));
    }

    private function date(mixed $value): ?string
    {
        if ($value === null) { return null; }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value)) {
            throw new DomainException('Date must include seconds and an explicit UTC offset.');
        }
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) { throw new DomainException('Invalid date.'); }
        return $date->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $args
     */
    private function optionalInt(array $args,string $key): ?int
    {
        $value=$args[$key]??null;
        if ($value!==null && !is_int($value)) { throw new DomainException('Invalid '.$key); }
        return $value;
    }

    /**
     * @param array<string,mixed> $args
     */
    private function requiredInt(array $args,string $key): int
    {
        return $this->optionalInt($args,$key)??throw new DomainException('Missing '.$key);
    }

    /**
     * @param array<string,mixed> $args
     */
    private function string(array $args,string $key,?string $default=null): string
    {
        $value=$args[$key]??$default;
        if (!is_string($value)) { throw new DomainException('Invalid '.$key); }
        return $value;
    }
}
