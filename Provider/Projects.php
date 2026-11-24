<?php

namespace Kanboard\Plugin\Switchboard\Provider;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectModel;
use Kanboard\Plugin\Switchboard\Model\LikeTerm;

final class Projects extends Base
{
    public function search(array $user, string $term, ?string $cursor, int $limit): array
    {
        $query = $this->db->table(ProjectModel::TABLE)->columns('id', 'name')->eq('is_active', ProjectModel::ACTIVE);
        if ($user['role'] !== Role::APP_ADMIN) {
            $projectIds = $this->projectPermissionModel->getActiveProjectIds($user['id']);
            if ($projectIds === []) {
                return ['entries' => [], 'cursor' => null];
            }
            $query->in('id', $projectIds);
        }

        $offset = ctype_digit($cursor ?? '') ? (int) $cursor : 0;
        $rows = $query->addCondition(LikeTerm::contains($this->db, 'name', $term))->asc('name')->asc('id')->offset($offset)->limit($limit)->findAll();
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'id' => (int) $row['id'],
                'title' => $row['name'],
                'url' => $this->helper->url->to('BoardViewController', 'show', ['project_id' => $row['id']]),
            ];
        }

        return ['entries' => $entries, 'cursor' => count($rows) === $limit ? (string) ($offset + $limit) : null];
    }
}
