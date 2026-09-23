<?php

declare(strict_types=1);

namespace Tms\Domain\Event;

final class DomainEventCatalog
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'task.created',
            'task.updated',
            'task.deleted',
            'task.checklist_added',
            'task.checklist_updated',
            'task.checklist_completed',
            'task.checklist_reopened',
            'task.checklist_reordered',
            'task.checklist_deleted',
            'project.created',
            'project.updated',
            'project.deleted',
            'discussion.comment.created',
            'discussion.comment.updated',
            'discussion.comment.deleted',
        ];
    }

    /** @param list<string> $types
     * @return list<string>
     */
    public static function normalize(array $types): array
    {
        $allowed = array_flip(self::all());
        $normalized = [];
        foreach ($types as $type) {
            $type = trim($type);
            if ($type !== '' && isset($allowed[$type])) {
                $normalized[$type] = true;
            }
        }
        $result = array_keys($normalized);
        sort($result);
        return $result;
    }
}
