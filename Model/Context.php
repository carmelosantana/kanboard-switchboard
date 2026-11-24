<?php

namespace Kanboard\Plugin\Switchboard\Model;

use Kanboard\Core\Http\Request;
use Pimple\Container;

final class Context
{
    public static function fromRequest(Container $c): array
    {
        $request = $c['request'];
        $userId = $c['userSession']->getId();
        $projectId = self::queryId($request, 'project_id');
        $taskId = self::queryId($request, 'task_id');

        if ($projectId !== null && (!$c['projectModel']->getById($projectId)
            || !$c['projectPermissionModel']->isUserAllowed($projectId, $userId))) {
            $projectId = null;
        }
        if ($taskId !== null) {
            $task = $c['taskFinderModel']->getById($taskId);
            $taskProjectId = $task ? (int) $task['project_id'] : null;
            if ($taskProjectId === null || !$c['projectPermissionModel']->isUserAllowed($taskProjectId, $userId)) {
                $taskId = null;
            } else {
                $projectId = $taskProjectId;
            }
        }

        $route = [];
        foreach (['controller', 'action', 'plugin'] as $key) {
            $value = $request->getStringParam('from_'.$key);
            $route[$key] = is_string($value) && mb_check_encoding($value, 'UTF-8')
                ? mb_substr($value, 0, 64, 'UTF-8')
                : '';
        }

        return ['user_id' => $userId, 'route' => $route, 'project_id' => $projectId, 'task_id' => $taskId];
    }

    private static function queryId(Request $request, string $name): ?int
    {
        $value = $request->getStringParam($name);
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 && (string) $id === ltrim($value, '0') ? $id : null;
    }
}
