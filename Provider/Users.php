<?php

namespace Kanboard\Plugin\Switchboard\Provider;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\GroupMemberModel;
use Kanboard\Model\ProjectGroupRoleModel;
use Kanboard\Model\ProjectUserRoleModel;
use Kanboard\Model\UserModel;

final class Users extends Base
{
    public function search(array $user, string $term, ?string $cursor, int $limit): array
    {
        $query = $this->db->table(UserModel::TABLE)->columns('id', 'username', 'name')->eq('is_active', 1);
        if ($user['role'] !== Role::APP_ADMIN) {
            $projectIds = $this->projectPermissionModel->getActiveProjectIds($user['id']);
            if ($projectIds === []) {
                return ['entries' => [], 'cursor' => null];
            }
            $directMembers = $this->db->table(ProjectUserRoleModel::TABLE)
                ->columns('user_id')
                ->in('project_id', $projectIds);
            $projectGroups = $this->db->table(ProjectGroupRoleModel::TABLE)
                ->columns('group_id')
                ->in('project_id', $projectIds);
            $groupMembers = $this->db->table(GroupMemberModel::TABLE)
                ->columns('user_id')
                ->inSubquery('group_id', $projectGroups);
            $query->beginOr()->inSubquery('id', $directMembers)->inSubquery('id', $groupMembers)->closeOr();
        }

        $pattern = '%'.$term.'%';
        $offset = ctype_digit($cursor ?? '') ? (int) $cursor : 0;
        $rows = $query->beginOr()->ilike('username', $pattern)->ilike('name', $pattern)->closeOr()
            ->asc('username')->asc('id')->offset($offset)->limit($limit)->findAll();
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'id' => (int) $row['id'],
                'title' => $row['name'] ?: $row['username'],
                'url' => $this->helper->url->to('UserViewController', 'profile', ['user_id' => $row['id']]),
            ];
        }

        return ['entries' => $entries, 'cursor' => count($rows) === $limit ? (string) ($offset + $limit) : null];
    }
}
