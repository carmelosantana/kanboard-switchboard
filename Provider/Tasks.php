<?php

namespace Kanboard\Plugin\Switchboard\Provider;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskModel;

final class Tasks extends Base
{
    public function search(array $user, string $term, ?string $cursor, int $limit): array
    {
        $query = $this->db->table(TaskModel::TABLE)->columns('id', 'title', 'project_id');
        if ($user['role'] === Role::APP_ADMIN) {
            $activeProjects = $this->db->table(ProjectModel::TABLE)
                ->columns('id')
                ->eq('is_active', ProjectModel::ACTIVE);
            $query->inSubquery('project_id', $activeProjects);
        } else {
            $projectIds = $this->projectPermissionModel->getActiveProjectIds($user['id']);
            if ($projectIds === []) {
                return ['entries' => [], 'cursor' => null];
            }
            $query->in('project_id', $projectIds);
        }

        if (ctype_digit($term)) {
            $query->eq('id', (int) $term);
        } else {
            $query->eq('is_active', TaskModel::STATUS_OPEN)->ilike('title', '%'.$term.'%');
        }

        $offset = ctype_digit($cursor ?? '') ? (int) $cursor : 0;
        $rows = $query->asc('id')->offset($offset)->limit($limit)->findAll();
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'title' => $row['title'],
                'url' => $this->helper->url->to('TaskViewController', 'show', [
                    'task_id' => $row['id'],
                    'project_id' => $row['project_id'],
                ]),
            ];
        }

        return ['entries' => $entries, 'cursor' => count($rows) === $limit ? (string) ($offset + $limit) : null];
    }
}
