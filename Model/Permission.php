<?php

namespace Kanboard\Plugin\Switchboard\Model;

use Closure;
use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Throwable;

class Permission extends Base
{
    private const APP_RANK = [
        Role::APP_USER => 1,
        Role::APP_MANAGER => 2,
        Role::APP_ADMIN => 3,
    ];

    private const PROJECT_RANK = [
        Role::PROJECT_VIEWER => 1,
        Role::PROJECT_MEMBER => 2,
        Role::PROJECT_MANAGER => 3,
    ];

    public function allows(mixed $spec, array $user, array $ctx): bool
    {
        if (($spec instanceof Closure || is_array($spec)) && is_callable($spec)) {
            try {
                return $spec($user, $ctx) === true;
            } catch (Throwable) {
                return false;
            }
        }

        $spec ??= Role::APP_USER;
        if (!is_string($spec)) {
            return false;
        }

        $appRole = $user['role'] ?? '';
        if (isset(self::APP_RANK[$spec])) {
            return (self::APP_RANK[$appRole] ?? 0) >= self::APP_RANK[$spec];
        }

        if (!isset(self::PROJECT_RANK[$spec])) {
            return false;
        }
        if ($appRole === Role::APP_ADMIN) {
            return true;
        }

        $projectId = $ctx['project_id'] ?? null;
        $userId = $user['id'] ?? null;
        if ($projectId === null || $userId === null) {
            return false;
        }

        $role = $this->projectUserRoleModel->getUserRole($projectId, $userId);
        return (self::PROJECT_RANK[$role] ?? 0) >= self::PROJECT_RANK[$spec];
    }
}
