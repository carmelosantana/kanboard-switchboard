<?php

namespace Kanboard\Plugin\Switchboard\Command;

use Kanboard\Core\Security\Role;

final class CoreCommands
{
    public static function forContext(array $ctx): array
    {
        $keys = [
            ['board-selector', 'Project switcher', 'b'],
            ['focus-filter', 'Focus filter', 'f'],
            ['reset-filter', 'Reset filter', 'r'],
            ['shortcuts', 'Keyboard shortcuts', '?'],
        ];
        if (isset($ctx['project_id'])) {
            $keys[] = ['view-overview', 'Project overview', 'v+o'];
            $keys[] = ['view-board', 'Board view', 'v+b'];
            $keys[] = ['view-list', 'List view', 'v+l'];
        }

        $commands = [];
        foreach ($keys as [$name, $title, $key]) {
            $commands[] = self::command($name, $title, ['kind' => 'key', 'key' => $key])
                + ['shortcut' => str_replace('+', ' ', $key)];
        }

        if (isset($ctx['project_id']) && ($ctx['route']['controller'] ?? null) === 'BoardViewController') {
            $commands[] = self::command('new-task', 'New task', [
                'kind' => 'modal',
                'controller' => 'TaskCreationController',
                'action' => 'show',
                'params' => ['project_id' => $ctx['project_id']],
                'size' => 'large',
            ], Role::PROJECT_MEMBER) + ['shortcut' => 'n'];
        }

        $commands[] = self::command('dashboard', 'Dashboard', [
            'kind' => 'route',
            'controller' => 'DashboardController',
            'action' => 'show',
        ]);
        $commands[] = self::command('settings', 'Switchboard settings', [
            'kind' => 'route',
            'controller' => 'SwitchboardSettingsController',
            'action' => 'show',
            'params' => ['plugin' => 'Switchboard'],
        ]);

        return $commands;
    }

    private static function command(string $name, string $title, array $target, string $permission = Role::APP_USER): array
    {
        return [
            'id' => 'switchboard.core.'.$name,
            'title' => $title,
            'group' => 'Kanboard',
            'risk' => 'read',
            'permission' => $permission,
            'target' => $target,
        ];
    }
}
