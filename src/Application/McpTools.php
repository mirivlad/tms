<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Tms\Domain\Checklist\ChecklistRepository;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldValueCodec;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Recurrence\RecurrenceSchedule;
use Tms\Domain\Recurrence\TaskRecurrenceRepository;
use Tms\Domain\UserPreference\UserPreferenceRepository;
use Tms\Domain\Project\ProjectRecord;
use Tms\Domain\Project\ProjectCustomFieldRepository;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\CustomField\TaskCustomFieldValueRepository;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\Domain\Team\TeamRepository;
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
        private readonly TaskTypeRepository $taskTypes,
        private readonly CustomerRepository $customers,
        private readonly TeamRepository $teams,
        private readonly CustomFieldRepository $customFields,
        private readonly ProjectCustomFieldRepository $projectCustomFields,
        private readonly TaskCustomFieldValueRepository $customValues,
        private readonly CustomFieldValueCodec $customCodec,
        private readonly ActivityRepository $activity,
        private readonly TaskRecurrenceRepository $recurrences,
        private readonly RecurrenceSchedule $recurrenceSchedule,
        private readonly UserPreferenceRepository $preferences,
        private readonly McpReferenceTools $referenceTools,
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
        $nullableId = ['type'=>['integer','null'],'minimum'=>1];
        $customInput = ['type'=>'object','maxProperties'=>100,'propertyNames'=>['pattern'=>'^[1-9][0-9]{0,18}$'],'additionalProperties'=>['anyOf'=>[
            ['type'=>['string','null'],'maxLength'=>20000],['type'=>'boolean'],
            ['type'=>'array','maxItems'=>100,'items'=>['type'=>'string','maxLength'=>1000]],
        ]],'description'=>'Map of numeric field IDs (as JSON keys) to text, boolean, string array or null; use decimal strings for money.'];
        $day = ['type'=>'string','pattern'=>'^\d{4}-\d{2}-\d{2}$','maxLength'=>10];
        $metadata = ['custom_fields'=>$customInput,'type_id' =>$nullableId,'customer_id'=>$nullableId,'assignee_user_id'=>$nullableId];
        $projectFields = [
            'name'=>['type'=>'string','minLength'=>1,'maxLength'=>160],
            'description'=>['type'=>'string','maxLength'=>20000],
            'lifecycle_status'=>['type'=>'string','enum'=>ProjectRepository::LIFECYCLE_STATUSES],
        ];
        $filterText=['type'=>'string','minLength'=>1,'maxLength'=>20000];
        $filterOptions=['type'=>'array','minItems'=>1,'maxItems'=>50,'items'=>['type'=>'string','minLength'=>1,'maxLength'=>128]];
        $customFilter=['type'=>'object','maxProperties'=>100,'propertyNames'=>['pattern'=>'^[1-9][0-9]{0,18}$'],
            'additionalProperties'=>['anyOf'=>[$filterText,['type'=>'boolean'],$filterOptions,
                ['type'=>'object','properties'=>['min'=>$filterText,'max'=>$filterText],'additionalProperties'=>false],
                ['type'=>'object','properties'=>['values'=>$filterOptions,'match'=>['type'=>'string','enum'=>['any','all']]],
                    'required'=>['values'],'additionalProperties'=>false],
            ]],'description'=>'Field-ID map. Text contains, select exact, checkbox boolean, money decimal string or min/max object, checkbox list options or values/match object. Requires a single project or without_project=true.'];
        $taskFilters = [
                'query'=>$str,'project_id'=>$id,'status_id'=>$id,'overdue'=>['type'=>'boolean'],
                'status_invert'=>['type'=>'boolean'],'without_project'=>['type'=>'boolean'],
                'type_id'=>$id,'customer_id'=>$id,'customer_query'=>$str,'priority'=>['type'=>'integer','minimum'=>0,'maximum'=>3],
                'custom_filters'=>$customFilter,
                'assignee_user_id'=>$id,'deadline_from'=>$day,'deadline_to'=>$day,
                'scheduled_from'=>$day,'scheduled_to'=>$day,'created_from'=>$day,'created_to'=>$day,
            ];
        $defs = [
            self::tool('list_projects','List accessible personal and team projects.', [], [], true),
            self::tool('get_project','Read an accessible project and its statuses.', ['project_id'=>$id], ['project_id'], true),
            self::tool('list_teams','List teams of which the caller is a member, with current role.', [], [], true),
            self::tool('list_project_assignees','List eligible team members for an accessible project; returns user IDs, names and roles only.', ['project_id'=>$id], ['project_id'], true),
            self::tool('list_task_types','List caller-owned personal task types.', [], [], true),
            self::tool('list_customers','List caller-owned personal customers.', [], [], true),
            self::tool('list_statuses','List statuses for a project, or personal statuses when omitted.', ['project_id'=>$id], [], true),
            self::tool('list_tasks','Find accessible tasks by filters. Returns a page of results.', $taskFilters+[
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],
                'offset'=>['type'=>'integer','minimum'=>0,'maximum'=>100000],
            ], [], true),
            self::tool('summarize_tasks','Count all matching accessible tasks grouped by status, project, priority and assignee. Uses the same filters as list_tasks without pagination.', $taskFilters, [], true),
            self::tool('list_custom_fields','List field definitions for a personal or project scope.', ['project_id'=>$id], [], true),
            self::tool('get_task_history','Read accessible task history.', ['task_id'=>$id,'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>200]], ['task_id'], true),
            self::tool('get_project_history','Read accessible project history.', ['project_id'=>$id,'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>200]], ['project_id'], true),
            self::tool('get_task','Get an accessible task including its checklist.', ['task_id'=>$id], ['task_id'], true),
        ];
        if ($write) {
            $defs[] = self::tool('create_task','Create a task, optionally with checklist items. Search existing tasks first. Dates require an explicit offset.',
                $fields + $metadata + ['project_id'=>$id,'request_key'=>$key,'checklist'=>['type'=>'array','maxItems'=>50,'items'=>['type'=>'string','minLength'=>1,'maxLength'=>500]]],
                ['title','request_key'], false);
            $defs[] = self::tool('update_task','Update supplied fields only. Moving a task selects the destination default status unless status_id is supplied. Clear incompatible metadata explicitly. Custom field values map by lineage; incompatible populated fields prevent a move.', $fields + $metadata + ['project_id'=>$nullableId,'task_id'=>$id,'request_key'=>$key], ['task_id','request_key'], false);
            $defs[] = self::tool('update_tasks','Atomically apply shared priority/status/type/customer/assignee/dates to 1–100 exact task IDs. All must be accessible; no project moves. Search and show the affected set first.',
                array_intersect_key($fields+$metadata,array_flip(['priority','status_id','type_id','customer_id','assignee_user_id','deadline','scheduled_at']))
                +['task_ids'=>['type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>$id],'request_key'=>$key],['task_ids','request_key'],false);
            $defs[] = self::tool('set_recurrence' ,'Set or replace an authored task recurrence. Calendar modes require a deadline; use a non-completion spawn status.',
                ['task_id'=>$id,'mode'=>['type'=>'string','enum'=>RecurrenceSchedule::MODES],
                 'interval_value'=>['type'=>'integer','minimum'=>1,'maximum'=>3650],'spawn_status_id'=>$id,'request_key'=>$key],
                ['task_id','mode','spawn_status_id','request_key'], false);
            $defs[] = self::tool('create_project' ,'Create a personal project or a team project (team lead only). Search accessible projects first.', $projectFields + ['team_id'=>$id,'request_key'=>$key], ['name','request_key'], false);
            $defs[] = self::tool('update_project','Edit name, description or lifecycle of an accessible manageable project. Ownership is unchanged.', $projectFields + ['project_id'=>$id,'request_key'=>$key], ['project_id','request_key'], false);
            $defs[] = self::tool('update_checklist_item','Rename a checklist item.', ['task_id'=>$id,'item_id'=>$id,'text'=>['type'=>'string','minLength'=>1,'maxLength'=>500],'request_key'=>$key], ['task_id','item_id','text','request_key'], false);
            $defs[] = self::tool('move_checklist_item','Move a checklist item one position up or down.', ['task_id'=>$id,'item_id'=>$id,'direction'=>['type'=>'string','enum'=>['up','down']],'request_key'=>$key], ['task_id','item_id','direction','request_key'], false);
            $defs[] = self::tool('complete_task','Move a task to its configured completion status.', ['task_id'=>$id,'request_key'=>$key], ['task_id','request_key'], false);
            $defs[] = self::tool('add_checklist_item','Add a checklist item to an accessible task.', ['task_id'=>$id,'text'=>['type'=>'string','minLength'=>1,'maxLength'=>500],'request_key'=>$key], ['task_id','text','request_key'], false);
            $defs[] = self::tool('set_checklist_completed','Set a checklist item completion state.', ['task_id'=>$id,'item_id'=>$id,'completed'=>['type'=>'boolean'],'request_key'=>$key], ['task_id','item_id','completed','request_key'], false);
        }
        if ($write && $delete) {
            $confirm = ['type'=>'boolean','description'=>'Must be true only after explicit user approval of these exact tasks.'];
            $defs[] = self::tool('delete_recurrence','Remove the recurrence of an authored task after explicit user approval; does not delete task instances.',
                ['task_id'=>$id,'confirmed'=>$confirm,'request_key'=>$key],['task_id','confirmed','request_key'],false);
            $defs[] = self::tool('delete_checklist_item' ,'Permanently remove a specific checklist item. Show the task and item IDs and obtain explicit user approval first.', ['task_id'=>$id,'item_id'=>$id,'confirmed'=>$confirm,'request_key'=>$key], ['task_id','item_id','confirmed','request_key'], false);
            $defs[] = self::tool('delete_task','Permanently delete one accessible task and its attachments. Show the task and obtain explicit user approval before calling.',
                ['task_id'=>$id,'confirmed'=>$confirm,'request_key'=>$key], ['task_id','confirmed','request_key'], false);
            $defs[] = self::tool('delete_tasks','Permanently delete 1–100 accessible tasks by explicit IDs, atomically. Show the exact selection and obtain explicit user approval before calling. Never delete by wildcard.',
                ['task_ids'=>['type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>$id],'confirmed'=>$confirm,'request_key'=>$key],
                ['task_ids','confirmed','request_key'], false);
        }
        return array_merge($defs,McpReferenceTools::definitions($write,$delete));
    }

    /**
     * @param array<string,mixed> $properties
     * @param list<string> $required
     * @return array<string,mixed>
     */
    private static function tool(string $name, string $description, array $properties, array $required, bool $read): array
    {
        return ['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>in_array($name,['update_task','complete_task','set_checklist_completed','delete_task','delete_tasks','update_project','update_checklist_item','move_checklist_item','delete_checklist_item','set_recurrence','delete_recurrence','update_tasks'],true),'idempotentHint'=>true,'openWorldHint'=>false]];
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
            'summarize_tasks'=>$this->summarizeTasks($uid,$args,$project),
            'list_custom_fields'=>['fields'=>$this->fieldsForScope($uid,$project)],
            'get_task_history'=>$this->taskHistory($uid,$this->requiredInt($args,'task_id'),$this->optionalInt($args,'limit')??100),
            'get_project_history'=>['history'=>$this->activity->listForProject($uid,$this->requiredInt($args,'project_id'),$this->optionalInt($args,'limit')??100)],
            'get_project'=>$this->projectDetail($uid,$this->requiredInt($args,'project_id')),
            'list_teams'=>['teams'=>$this->teams->listForUser($uid)],
            'list_project_assignees'=>['assignees'=>$this->assignees($uid,$this->requiredInt($args,'project_id'))],
            'list_task_types'=>['task_types'=>$this->taskTypes->listForUser($uid)],
            'list_customers'=>['customers'=>$this->customers->listAllForUser($uid)],
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
        $list = $this->filteredTasks($uid,$args,$project);
        return ['tasks'=>array_slice($list,$offset,$limit),'total'=>count($list),'next_offset'=>$offset+$limit<count($list)?$offset+$limit:null,'timezone'=>$this->timezone];
    }

    /**
     * @param array<string,mixed> $args
     * @return list<TaskRecord>
     */
    private function filteredTasks(int $uid,array $args,?int $project): array
    {
        $withoutProject = ($args['without_project']??false) === true;
        if ($project !== null && $withoutProject) { throw new DomainException('Conflicting project filters.'); }
        foreach (['deadline','scheduled','created'] as $prefix) {
            $from=$this->filterDay($args,$prefix.'_from');
            $to=$this->filterDay($args,$prefix.'_to');
            if ($from!=='' && $to!=='' && $from>$to) { throw new DomainException('Invalid date range.'); }
        }
        $list = $this->tasks->listFilteredForUser($uid,statusId:$this->optionalInt($args,'status_id'),
            statusInvert:($args['status_invert']??false)===true,typeId:$this->optionalInt($args,'type_id'),
            customerId:$this->optionalInt($args,'customer_id'),customerQuery:$this->string($args,'customer_query',''),priority:$this->optionalInt($args,'priority'),
            query:$this->string($args,'query',''),overdue:($args['overdue'] ?? false) === true,
            deadlineFrom:$this->filterDay($args,'deadline_from'),deadlineTo:$this->filterDay($args,'deadline_to'),
            scheduledFrom:$this->filterDay($args,'scheduled_from'),scheduledTo:$this->filterDay($args,'scheduled_to'),
            createdFrom:$this->filterDay($args,'created_from'),createdTo:$this->filterDay($args,'created_to'),
            projectId:$project,withoutProject:$withoutProject);
        $assignee=$this->optionalInt($args,'assignee_user_id');
        if ($assignee!==null) { $list=array_values(array_filter($list,static fn(TaskRecord $t):bool=>$t->assigneeUserId===$assignee)); }
        if (array_key_exists('custom_filters',$args)) {
            if ($project===null && !$withoutProject) { throw new DomainException('Custom filters require project_id or without_project=true.'); }
            $list=$this->filterCustomFields($uid,$project,$list,$args['custom_filters']);
        }
        return $list;
    }

    /**
     * @param list<TaskRecord> $tasks
     * @return list<TaskRecord>
     */
    private function filterCustomFields(int $uid,?int $project,array $tasks,mixed $input): array
    {
        if (!$input instanceof \stdClass) { throw new DomainException('Invalid custom filters.'); }
        $fields=[];
        foreach ($this->fieldsForScope($uid,$project) as $field) { $fields[$field->id]=$field; }
        $filters=[];
        foreach (get_object_vars($input) as $key=>$raw) {
            $id=(int)$key;
            $field=$fields[$id]??throw new DomainException('Unknown custom filter field.');
            if ($field->type==='text' || $field->type==='textarea' || $field->type==='select') {
                if (!is_string($raw) || trim($raw)==='') { throw new DomainException('Invalid text filter.'); }
                $raw=trim($raw);
                if ($field->type==='select' && !in_array($raw,$field->options,true)) { throw new DomainException('Unknown select option.'); }
                $filters[$id]=$raw;
            } elseif ($field->type==='checkbox') {
                if (!is_bool($raw)) { throw new DomainException('Checkbox filter must be boolean.'); }
                $filters[$id]=$raw?'1':'0';
            } elseif ($field->type==='money') {
                $range=is_string($raw)?['min'=>$raw,'max'=>$raw]:($raw instanceof \stdClass?get_object_vars($raw):[]);
                if ($range===[] || array_diff(array_keys($range),['min','max'])!==[]) { throw new DomainException('Invalid money filter.'); }
                $normalized=[];
                foreach ($range as $bound=>$value) {
                    $amount=$this->customCodec->normalizeMoneyInput($value);
                    if ($amount===null) { throw new DomainException('Invalid money bound.'); }
                    $normalized[$bound]=$amount;
                }
                if (isset($normalized['min'],$normalized['max']) && $this->customCodec->moneyMinorUnits($normalized['min'])>$this->customCodec->moneyMinorUnits($normalized['max'])) {
                    throw new DomainException('Money range is reversed.');
                }
                $filters[$id]=$normalized;
            } elseif ($field->type==='checkbox_list') {
                $payload=is_array($raw)?['values'=>$raw]:($raw instanceof \stdClass?get_object_vars($raw):[]);
                $options=$payload['values']??[];
                if (!is_array($options) || $options===[] || array_diff($options,$field->options)!==[]) {
                    throw new DomainException('Unknown checkbox-list option.');
                }
                $filters[$id]=['values'=>$options,'match'=>$payload['match']??'any'];
            } else { throw new DomainException('Unsupported custom filter field.'); }
        }
        if ($filters===[]) { return $tasks; }
        $matched=[];
        foreach (array_chunk($tasks,500) as $chunk) {
            $stored=$this->customValues->listForTasks($uid,array_map(static fn(TaskRecord $t):int=>$t->id,$chunk));
            foreach ($chunk as $task) {
                foreach ($filters as $id=>$filter) {
                    if (!$this->customCodec->matchesFilter($fields[$id],$stored[$task->id][$id]??null,$filter)) { continue 2; }
                }
                $matched[]=$task;
            }
        }
        return $matched;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function summarizeTasks(int $uid,array $args,?int $project): array
    {
        $list=$this->filteredTasks($uid,$args,$project);
        $groups=['status'=>[],'project'=>[],'priority'=>[],'assignee'=>[]];
        foreach ($list as $task) {
            foreach (['status'=>$task->statusId,'project'=>$task->projectId,'priority'=>$task->priority,'assignee'=>$task->assigneeUserId] as $kind=>$id) {
                $key=$id===null?'none':(string)$id;
                $groups[$kind][$key]=($groups[$kind][$key]??0)+1;
            }
        }
        return ['total'=>count($list),'by_status'=>(object)$groups['status'],'by_project'=>(object)$groups['project'],
            'by_priority'=>(object)$groups['priority'],'by_assignee'=>(object)$groups['assignee'],'timezone'=>$this->timezone];
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(int $uid,int $id): array
    {
        $task=$this->task($uid,$id);
        $stored=$this->customValues->listForTask($uid,$id);
        $values=[];
        foreach ($this->fieldsForScope($uid,$task->projectId) as $field) {
            $values[$field->id]=$this->decodeField($field,$stored[$field->id]??null);
        }
        return ['task'=>$task,'checklist'=>$this->checklists->listForTask($uid,$id),
            'custom_fields'=>(object)$values,'recurrence'=>$task->ownerId===$uid?$this->recurrences->findForTask($uid,$id):null,
            'timezone'=>$this->timezone];
    }

    /** @return array<string,mixed> */
    public function projectDetail(int $uid, int $id): array
    {
        $project = $this->project($uid,$id);
        return ['project'=>$project,'statuses'=>$this->scopeStatuses($uid,$id),
            'can_manage'=>$this->projects->canManageForUser($uid,$id),'timezone'=>$this->timezone];
    }

    private function project(int $uid,int $id): ProjectRecord
    {
        return $this->projects->findForUser($uid,$id) ?? throw new DomainException('Project not found or inaccessible.');
    }

    /** @return list<array{user_id:int,username:string,role:string}> */
    private function assignees(int $uid,int $projectId): array
    {
        $team = $this->project($uid,$projectId)->ownerTeamId;
        if ($team === null) { return []; }
        return array_map(static fn ($m) => ['user_id'=>$m->userId,'username'=>$m->username,'role'=>$m->role],
            $this->teams->listMembers($uid,$team));
    }

    /** @param array<string,mixed> $args */
    public function mutateProject(int $uid,string $name,array $args): int
    {
        if ($name === 'create_project') {
            $team = $this->optionalInt($args,'team_id');
            $title = $this->string($args,'name');
            $description = $this->string($args,'description','');
            $lifecycle = $this->string($args,'lifecycle_status','active');
            $id = $team === null
                ? $this->projects->createForUser($uid,$title,$description,$lifecycle)
                : $this->projects->createForTeam($uid,$team,$title,$description,$lifecycle);
            $this->events->projectCreated($uid,$this->project($uid,$id));
            return $id;
        }
        $id = $this->requiredInt($args,'project_id');
        $before = $this->projects->findManageableForUser($uid,$id,true)
            ?? throw new DomainException('Project not found or not manageable.');
        if (!$this->projects->updateForUser($uid,$id,
            $this->string($args,'name',$before->name),
            $this->string($args,'description',$before->description),
            $this->string($args,'lifecycle_status',$before->lifecycleStatus))) {
            throw new DomainException('Project unavailable.');
        }
        $this->events->projectChanged($uid,$before,$this->project($uid,$id));
        return $id;
    }

    /** @param array<string,mixed> $args */
    private function filterDay(array $args,string $key): string
    {
        $day=$this->string($args,$key,'');
        if ($day==='') { return ''; }
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
        if ($parsed===false || $parsed->format('Y-m-d')!==$day) { throw new DomainException('Invalid calendar date.'); }
        return $day;
    }

    /** @return array<string,mixed> */
    private function taskHistory(int $uid,int $id,int $limit): array
    {
        $this->task($uid,$id);
        return ['history'=>$this->activity->listForTask($uid,$id,$limit)];
    }

    /** @return list<CustomFieldRecord> */
    private function fieldsForScope(int $uid,?int $project): array
    {
        return $project===null?$this->customFields->listForUser($uid):$this->projectCustomFields->listForProject($uid,$project);
    }

    private function decodeField(CustomFieldRecord $field,?string $value): mixed
    {
        return match($field->type) {
            'checkbox'=>$value==='1',
            'checkbox_list'=>$this->customCodec->selectedOptions($field,$value),
            default=>$value,
        };
    }

    /**
     * Preserve unspecified fields; remap only matching field lineage and type.
     * Reject loss of populated values instead of silently dropping them.
     * @param array<string,mixed> $args
     * @return array<int,string|null>
     */
    private function fieldValues(int $uid,?int $project,array $args,?TaskRecord $before=null): array
    {
        $input=$args['custom_fields']??new \stdClass();
        if (!$input instanceof \stdClass) { throw new DomainException('Invalid custom fields.'); }
        $provided=get_object_vars($input);
        $target=$this->fieldsForScope($uid,$project);
        $source=$before===null?[]:$this->fieldsForScope($uid,$before->projectId);
        $stored=$before===null?[]:$this->customValues->listForTask($uid,$before->id);
        $sourceByLineage=[];
        foreach ($source as $field) {
            $lineage=$field->isPersonal()?$field->id:$field->sourceFieldId;
            if ($lineage!==null) { $sourceByLineage[$lineage]=$field; }
        }
        $mapped=[];
        $values=[];
        foreach ($target as $field) {
            $lineage=$field->isPersonal()?$field->id:$field->sourceFieldId;
            $old=$before!==null && $before->projectId===$project
                ? $field : ($lineage===null?null:($sourceByLineage[$lineage]??null));
            $raw=null;
            if ($old!==null && $old->type===$field->type && array_key_exists($old->id,$stored)) {
                $mapped[$old->id]=true;
                $raw=$this->decodeField($old,$stored[$old->id]);
            }
            if (array_key_exists($field->id,$provided)) {
                $raw=$provided[$field->id];
                unset($provided[$field->id]);
                $valid=match($field->type) {
                    'checkbox'=>is_bool($raw)||$raw===null,
                    'checkbox_list'=>is_array($raw)||$raw===null,
                    default=>is_string($raw)||$raw===null,
                };
                if (!$valid) { throw new DomainException('Wrong value type for custom field '.$field->id); }
            }
            $values[$field->id]=$this->customCodec->encode($field,$raw);
        }
        if ($provided!==[]) { throw new DomainException('Unknown custom field for task scope.'); }
        if ($before!==null && $before->projectId!==$project) {
            foreach ($stored as $fieldId=>$value) {
                if (!isset($mapped[$fieldId]) && $value!=='') { throw new DomainException('Move would lose custom field '.$fieldId.'. Use the web form for explicit remapping.'); }
            }
        }
        return $values;
    }

    /** @param array<string,mixed> $args */
    private function setRecurrence(int $uid,TaskRecord $task,array $args): void
    {
        if ($task->ownerId!==$uid) { throw new DomainException('Only the task author can manage recurrence.'); }
        $mode=$this->string($args,'mode');
        $interval=$this->recurrenceSchedule->normalizeInterval($mode,$this->optionalInt($args,'interval_value')??1);
        $spawn=$this->requiredInt($args,'spawn_status_id');
        $valid=false;
        foreach ($this->scopeStatuses($uid,$task->projectId) as $status) {
            if ($status->id===$spawn && !$status->isCompletion) { $valid=true; break; }
        }
        if (!$valid) { throw new DomainException('Selected recurrence status is unavailable.'); }
        $timezone=$this->preferences->getForUser($uid)->timezone;
        $anchor=null;$nextDeadline=null;$nextRun=null;
        if ($mode!=='after_completion') {
            if ($task->deadline===null || $task->deadline==='') { throw new DomainException('Calendar recurrence requires a task deadline.'); }
            $seed=new DateTimeImmutable($task->deadline,new DateTimeZone($timezone));
            $anchor=(int)$seed->format('j');
            $next=$this->recurrenceSchedule->nextCalendarDeadline($mode,$interval,$seed,$anchor);
            $nextDeadline=$next->format('Y-m-d H:i:s');
            $nextRun=$next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $this->recurrences->saveForTask($uid,$task->id,$mode,$interval,$spawn,$timezone,$anchor,$nextDeadline,$nextRun);
    }

    public static function isGeneralMutation(string $name): bool
    {
        return $name==='update_tasks'||McpReferenceTools::handles($name);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function mutateGeneral(int $uid,string $name,array $args): array
    {
        if ($name!=='update_tasks') { return $this->referenceTools->mutate($uid,$name,$args); }
        $ids=$args['task_ids'];
        if (!is_array($ids) || $ids===[] || count(array_unique($ids))!==count($ids)) { throw new DomainException('Distinct task IDs required.'); }
        sort($ids,SORT_NUMERIC);
        unset($args['task_ids']);
        if ($args===[]) { throw new DomainException('At least one update field is required.'); }
        foreach ($ids as $id) {
            if ($this->tasks->findForUser($uid,(int)$id,true)===null) { throw new DomainException('Task unavailable; no tasks updated.'); }
        }
        foreach ($ids as $id) { $this->mutate($uid,'update_task',['task_id'=>$id]+$args); }
        return ['updated_ids'=>$ids,'updated_count'=>count($ids)];
    }

    /** @param array<string,mixed> $args */
    public function assertGeneralReplayScope(int $uid,string $name,array $args): void
    {
        if ($name==='update_tasks') {
            foreach ($args['task_ids'] as $id) { $this->task($uid,(int)$id); }
        } else { $this->referenceTools->assertScope($uid,$args); }
    }

    /**
     * @param array<string,mixed> $args
     */
    public function mutate(int $uid,string $name,array $args): int
    {
        if ($name === 'create_task') {
            $project = $this->optionalInt($args,'project_id');
            if ($project !== null) { $this->project($uid,$project); }
            $values=$this->fieldValues($uid,$project,$args);
            $status = $this->optionalInt($args,'status_id') ?? $this->configuredStatus($uid,$project,false);
            $id = $this->tasks->createForUser($uid,$this->string($args,'title'),$this->description($this->string($args,'description','')),
                $this->date($args['deadline'] ?? null),$status,$this->optionalInt($args,'type_id'),$this->optionalInt($args,'priority') ?? 1,$this->optionalInt($args,'customer_id'),$project,$this->optionalInt($args,'assignee_user_id'),$this->date($args['scheduled_at'] ?? null));
            if ($values!==[]) { $this->customValues->replaceForTask($uid,$id,$values); }
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
        $before = $this->tasks->findForUser($uid,$id,true) ?? throw new DomainException('Task not found or inaccessible.');
        switch($name) {
            case 'update_task':
                $project = $this->changedId($args,'project_id',$before->projectId);
                if ($project !== null) { $this->project($uid,$project); }
                $moving = $project !== $before->projectId;
                if ($moving && $this->recurrences->findForTask($uid,$id)!==null) {
                    throw new DomainException('Remove recurrence before moving a recurring task.');
                }
                $values=($moving || array_key_exists('custom_fields',$args))?$this->fieldValues($uid,$project,$args,$before):null;
                $status = $this->optionalInt($args,'status_id')
                    ?? ($moving ? null : $before->statusId) ?? $this->configuredStatus($uid,$project,false);
                if (!$this->tasks->updateForUser($uid,$id,$this->string($args,'title',$before->title),
                    array_key_exists('description',$args)?$this->description($this->string($args,'description')):$before->description,
                    array_key_exists('deadline',$args)?$this->date($args['deadline']):$before->deadline,
                    $status,$this->changedId($args,'type_id',$before->typeId),$this->optionalInt($args,'priority')??$before->priority,$this->changedId($args,'customer_id',$before->customerId),
                    $project,$this->changedId($args,'assignee_user_id',$before->assigneeUserId),array_key_exists('scheduled_at',$args)?$this->date($args['scheduled_at']):$before->scheduledAt)) {
                    throw new DomainException('Task unavailable.');
                }
                if ($values!==null) { $this->customValues->replaceForTask($uid,$id,$values); }
                $this->events->taskChanged($uid,$before,$this->task($uid,$id));
                break;
            case 'set_recurrence':
                $this->setRecurrence($uid,$before,$args);
                break;
            case 'delete_recurrence':
                if ($before->ownerId!==$uid || ($args['confirmed']??false)!==true) {
                    throw new DomainException('Task author and explicit confirmation required.');
                }
                $this->recurrences->deleteForTask($uid,$id);
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
            case 'update_checklist_item':
            case 'move_checklist_item':
            case 'delete_checklist_item':
                $itemId = $this->requiredInt($args,'item_id');
                $item = $this->checklists->findForTask($uid,$id,$itemId)
                    ?? throw new DomainException('Checklist item unavailable.');
                if ($name === 'update_checklist_item') {
                    if (!$this->checklists->updateText($uid,$id,$itemId,$this->string($args,'text'))) {
                        throw new DomainException('Checklist item unavailable.');
                    }
                    $after = $this->checklists->findForTask($uid,$id,$itemId);
                    if ($after->text !== $item->text) {
                        $this->events->taskEvent($uid,$before,'task.checklist_updated',['checklist_item'=>['old'=>$item->text,'new'=>$after->text]]);
                    }
                } elseif ($name === 'move_checklist_item') {
                    if ($this->checklists->move($uid,$id,$itemId,$this->string($args,'direction'))) {
                        $this->events->taskEvent($uid,$before,'task.checklist_reordered',['checklist_item'=>['old'=>null,'new'=>$item->text]]);
                    }
                } else {
                    if (($args['confirmed'] ?? false) !== true) { throw new DomainException('Explicit deletion confirmation required.'); }
                    if (!$this->checklists->delete($uid,$id,$itemId)) { throw new DomainException('Checklist item unavailable.'); }
                    $this->events->taskEvent($uid,$before,'task.checklist_deleted',['checklist_item'=>['old'=>$item->text,'new'=>null]]);
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
    private function changedId(array $args,string $key,?int $previous): ?int
    {
        return array_key_exists($key,$args) ? $this->optionalInt($args,$key) : $previous;
    }

    /** @param array<string,mixed> $args */
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
