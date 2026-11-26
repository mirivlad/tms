<?php

declare(strict_types=1);

namespace Tms\Application;

use DomainException;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\Project\ProjectCustomFieldRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\TaskType\TaskTypeRepository;

/**
 * Reference mutations reuse domain repositories, with the MCP controller owning
 * the transaction and request receipt. Results contain only identifiers.
 */
final class McpReferenceTools
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly StatusRepository $statuses,
        private readonly ProjectStatusRepository $projectStatuses,
        private readonly TaskTypeRepository $types,
        private readonly CustomerRepository $customers,
        private readonly CustomFieldRepository $fields,
        private readonly ProjectCustomFieldRepository $projectFields,
    ) {}

    public static function handles(string $name): bool
    {
        return in_array($name,['create_reference','update_reference','reorder_references','set_status_role',
            'delete_reference','replace_custom_field_definition'],true);
    }

    /** @return list<array<string,mixed>> */
    public static function definitions(bool $write,bool $delete): array
    {
        if (!$write) { return []; }
        $id=['type'=>'integer','minimum'=>1];
        $kind=['type'=>'string','enum'=>['status','task_type','customer','custom_field']];
        $scope=['kind'=>$kind,'project_id'=>$id];
        $key=['request_key'=>['type'=>'string','minLength'=>1,'maxLength'=>128,'pattern'=>'^[A-Za-z0-9_.:-]+$']];
        $bool=['type'=>'boolean'];
        $name=['name'=>['type'=>'string','minLength'=>1,'maxLength'=>255]];
        $attributes=[
            'description'=>['type'=>'string','maxLength'=>512],
            'color'=>['type'=>'string','pattern'=>'^#[0-9a-fA-F]{6}$','maxLength'=>7],
            'show_on_board'=>$bool,'is_required'=>$bool,
        ];
        $fieldDefinition=[
            'field_type'=>['type'=>'string','enum'=>CustomFieldRepository::TYPES],
            'options'=>['type'=>'array','maxItems'=>50,'items'=>['type'=>'string','maxLength'=>128]],
        ];
        $defs=[
            self::tool('create_reference','Create a status, personal task type/customer, or personal/project custom field. project_id is only valid for status and custom_field; field_type is required for custom_field. Search the corresponding list first.',
                $scope+$name+$attributes+$fieldDefinition+$key,['kind','name','request_key']),
            self::tool('update_reference','Patch a reference name or applicable attributes. Custom field type/options are immutable here; use replace_custom_field_definition with explicit approval for potentially destructive changes.',
                $scope+['reference_id'=>$id]+$name+$attributes+$key,['kind','reference_id','request_key']),
            self::tool('reorder_references','Reorder all references in one scope using the exact full ID set. Customers do not have a manual sort order.',
                $scope+['reference_ids'=>['type'=>'array','minItems'=>1,'maxItems'=>500,'items'=>$id]]+$key,
                ['kind','reference_ids','request_key']),
            self::tool('set_status_role','Select the default or completion status within the personal/project workflow.',
                ['project_id'=>$id,'status_id'=>$id,'role'=>['type'=>'string','enum'=>['default','completion']]]+$key,
                ['status_id','role','request_key']),
        ];
        if ($delete) {
            $confirm=['confirmed'=>['type'=>'boolean','description'=>'True only after the user explicitly approves this exact reference and consequences.']];
            $defs[]=self::tool('delete_reference','Permanently delete one reference. Used statuses/types/customers cannot be deleted; custom field deletion removes its stored values. Show exact ID and obtain explicit approval.',
                $scope+['reference_id'=>$id]+$confirm+$key,['kind','reference_id','confirmed','request_key']);
            $defs[]=self::tool('replace_custom_field_definition','Change field type/options, which may clear or prune existing values. Show exact field ID and consequences and obtain explicit approval.',
                ['project_id'=>$id,'reference_id'=>$id]+$fieldDefinition+$confirm+$key,
                ['reference_id','field_type','confirmed','request_key']);
        }
        return $defs;
    }

    /**
     * @param array<string,mixed> $properties
     * @param list<string> $required
     * @return array<string,mixed>
     */
    private static function tool(string $name,string $description,array $properties,array $required): array
    {
        return ['name'=>$name,'description'=>$description,'inputSchema'=>[
            'type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false,
        ],'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>$name!=='create_reference','idempotentHint'=>true,'openWorldHint'=>false]];
    }

    /** @param array<string,mixed> $args */
    public function assertScope(int $uid,array $args): void
    {
        $project=$args['project_id']??null;
        if ($project!==null && $this->projects->findManageableForUser($uid,(int)$project,true)===null) {
            throw new DomainException('Project not found or not manageable.');
        }
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function mutate(int $uid,string $tool,array $args): array
    {
        $project=isset($args['project_id'])?(int)$args['project_id']:null;
        $this->assertScope($uid,$args);
        $kind=match($tool) {
            'set_status_role'=>'status',
            'replace_custom_field_definition'=>'custom_field',
            default=>(string)($args['kind']??''),
        };
        if ($project!==null && !in_array($kind,['status','custom_field'],true)) {
            throw new DomainException('This reference is personal only.');
        }
        $allowed=match($kind) {
            'status'=>['name','description','color','show_on_board'],
            'task_type'=>['name','description'],
            'customer'=>['name'],
            'custom_field'=>['name','field_type','options','is_required'],
            default=>throw new DomainException('Unsupported reference kind.'),
        };
        foreach (['name','description','color','show_on_board','field_type','options','is_required'] as $field) {
            if (array_key_exists($field,$args) && !in_array($field,$allowed,true)) {
                throw new DomainException('Argument '.$field.' is not applicable to '.$kind);
            }
        }
        if ($tool==='reorder_references') {
            /** @var list<int> $ids */
            $ids=$args['reference_ids'];
            $ok=match($kind) {
                'status'=>$project===null?$this->statuses->reorderForUser($uid,$ids):$this->projectStatuses->reorderForProject($uid,$project,$ids),
                'task_type'=>$this->types->reorderForUser($uid,$ids),
                'custom_field'=>$project===null?$this->fields->reorderForUser($uid,$ids):$this->projectFields->reorderForProject($uid,$project,$ids),
                default=>throw new DomainException('This reference does not support ordering.'),
            };
            if (!$ok) { throw new DomainException('Reference IDs must match the exact accessible scope.'); }
            return ['kind'=>$kind,'reference_ids'=>$ids];
        }
        $id=(int)($args['reference_id']??$args['status_id']??0);
        if ($tool==='delete_reference') {
            $this->confirm($args);
            $ok=match($kind) {
                'status'=>$project===null?$this->statuses->deleteForUser($uid,$id):$this->projectStatuses->deleteForProject($uid,$project,$id),
                'task_type'=>$this->types->deleteForUser($uid,$id),
                'customer'=>$this->customers->deleteForUser($uid,$id),
                'custom_field'=>$project===null?$this->fields->deleteForUser($uid,$id):$this->projectFields->deleteForProject($uid,$project,$id),
            };
            if (!$ok) { throw new DomainException('Reference is unavailable, protected or in use.'); }
            return ['kind'=>$kind,'reference_id'=>$id,'deleted'=>true];
        }
        if ($tool==='set_status_role') {
            $completion=$args['role']==='completion';
            $ok=$project===null
                ? ($completion?$this->statuses->setCompletionForUser($uid,$id):$this->statuses->setDefaultForUser($uid,$id))
                : ($completion?$this->projectStatuses->setCompletionForProject($uid,$project,$id):$this->projectStatuses->setDefaultForProject($uid,$project,$id));
            if (!$ok) { throw new DomainException('Status is unavailable.'); }
            return ['kind'=>$kind,'reference_id'=>$id];
        }
        if ($tool==='create_reference') {
            $name=trim((string)$args['name']);
            if ($kind!=='customer' && mb_strlen($name)>96) { throw new DomainException('Reference name cannot exceed 96 characters.'); }
            $description=(string)($args['description']??'');
            $color=(string)($args['color']??'#6b7280');
            $show=(bool)($args['show_on_board']??true);
            $required=(bool)($args['is_required']??false);
            /** @var list<string> $options */
            $options=$args['options']??[];
            $type=(string)($args['field_type']??'');
            $id=match($kind) {
                'status'=>$project===null?$this->statuses->createForUser($uid,$name,$description,$color,false,false,$show):
                    $this->projectStatuses->createForProject($uid,$project,$name,$description,$color,false,false,$show),
                'task_type'=>$this->types->createForUser($uid,$name,$description),
                'customer'=>$this->customers->createForUser($uid,$name),
                'custom_field'=>$project===null?$this->fields->createForUser($uid,$name,$type,$options,$required):
                    $this->projectFields->createForProject($uid,$project,$name,$type,$options,$required),
            };
            return ['kind'=>$kind,'reference_id'=>$id];
        }
        if ($kind==='status') {
            $old=($project===null?$this->statuses->findForUser($uid,$id):$this->projectStatuses->findForProject($uid,$project,$id))
                ??throw new DomainException('Reference unavailable.');
            $name=(string)($args['name']??$old->name);
            $desc=(string)($args['description']??$old->description);
            $color=(string)($args['color']??$old->color);
            $show=(bool)($args['show_on_board']??$old->showOnBoard);
            $ok=$project===null?$this->statuses->updateForUser($uid,$id,$name,$desc,$color,$show):
                $this->projectStatuses->updateForProject($uid,$project,$id,$name,$desc,$color,$show);
        } elseif ($kind==='task_type') {
            $old=$this->types->findForUser($uid,$id)??throw new DomainException('Reference unavailable.');
            $name=(string)($args['name']??$old->name);
            if (mb_strlen(trim($name))>96) { throw new DomainException('Reference name cannot exceed 96 characters.'); }
            $ok=$this->types->updateForUser($uid,$id,$name,(string)($args['description']??$old->description));
        } elseif ($kind==='customer') {
            $old=$this->customers->findForUser($uid,$id)??throw new DomainException('Reference unavailable.');
            $ok=$this->customers->updateForUser($uid,$id,(string)($args['name']??$old->name));
        } else {
            $old=($project===null?$this->fields->findForUser($uid,$id):$this->projectFields->findForProject($uid,$project,$id))
                ??throw new DomainException('Reference unavailable.');
            $type=$old->type;
            $options=$old->options;
            if ($tool==='replace_custom_field_definition') {
                $this->confirm($args);
                $type=(string)$args['field_type'];
                /** @var list<string> $options */
                $options=$args['options']??($type===$old->type?$old->options:[]);
            }
            $name=(string)($args['name']??$old->name);
            $required=(bool)($args['is_required']??$old->isRequired);
            $ok=$project===null?$this->fields->updateForUser($uid,$id,$name,$type,$options,$required):
                $this->projectFields->updateForProject($uid,$project,$id,$name,$type,$options,$required);
        }
        if (!$ok) { throw new DomainException('Reference unavailable.'); }
        return ['kind'=>$kind,'reference_id'=>$id];
    }

    /** @param array<string,mixed> $args */
    private function confirm(array $args): void
    {
        if (($args['confirmed']??false)!==true) { throw new DomainException('Explicit user confirmation required.'); }
    }
}
