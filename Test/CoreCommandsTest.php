<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Command\CoreCommands;
use Kanboard\Plugin\Switchboard\Plugin;
use KanboardTests\units\Base;
use PicoDb\Database;

final class CoreCommandsTest extends Base
{
    private const BOARD = ['project_id' => 15, 'route' => ['controller' => 'BoardViewController']];

    public function testCommandsWithoutAProject(): void
    {
        $commands = array_column(CoreCommands::forContext(['route' => self::BOARD['route']]), null, 'id');
        $this->assertEqualsCanonicalizing([
            'switchboard.core.board-selector',
            'switchboard.core.focus-filter',
            'switchboard.core.reset-filter',
            'switchboard.core.shortcuts',
            'switchboard.core.dashboard',
            'switchboard.core.settings',
        ], array_keys($commands));

        foreach (['board-selector' => 'b', 'focus-filter' => 'f', 'reset-filter' => 'r', 'shortcuts' => '?'] as $name => $key) {
            $command = $commands['switchboard.core.'.$name];
            $this->assertSame($key, $command['shortcut']);
            $this->assertSame(['kind' => 'key', 'key' => $key], $command['target']);
        }
        $this->assertSame([
            'kind' => 'route', 'controller' => 'DashboardController', 'action' => 'show',
        ], $commands['switchboard.core.dashboard']['target']);
        $this->assertSame([
            'kind' => 'route', 'controller' => 'SettingsController', 'action' => 'show', 'params' => ['plugin' => 'Switchboard'],
        ], $commands['switchboard.core.settings']['target']);
    }

    public function testNewTaskIsABoardOnlyModal(): void
    {
        $commands = array_column(CoreCommands::forContext(self::BOARD), null, 'id');
        $newTask = $commands['switchboard.core.new-task'];

        $this->assertSame('n', $newTask['shortcut']);
        $this->assertSame(Role::PROJECT_MEMBER, $newTask['permission']);
        $this->assertSame([
            'kind' => 'modal', 'controller' => 'TaskCreationController', 'action' => 'show',
            'params' => ['project_id' => 15], 'size' => 'large',
        ], $newTask['target']);

        $otherPage = array_column(CoreCommands::forContext([
            'project_id' => 15, 'route' => ['controller' => 'TaskViewController'],
        ]), null, 'id');
        $this->assertArrayNotHasKey('switchboard.core.new-task', $otherPage);

        foreach (['overview' => 'o', 'board' => 'b', 'list' => 'l'] as $view => $key) {
            $command = $otherPage['switchboard.core.view-'.$view];
            $this->assertSame('v '.$key, $command['shortcut']);
            $this->assertSame(['kind' => 'key', 'key' => 'v+'.$key], $command['target']);
        }
    }

    public function testRegisteredCommandsHideNewTaskFromProjectViewers(): void
    {
        $this->container['hook'] = new Hook();
        (new Plugin($this->container))->initialize();
        $registry = $this->container['switchboardRegistry'];
        $projectId = $this->container['projectModel']->create(['name' => 'Board']);
        $users = $this->container['userModel'];
        $roles = $this->container['projectUserRoleModel'];
        $viewer = ['id' => $users->create(['username' => 'viewer']), 'role' => Role::APP_USER];
        $member = ['id' => $users->create(['username' => 'member']), 'role' => Role::APP_USER];
        $this->assertTrue($roles->addUser($projectId, $viewer['id'], Role::PROJECT_VIEWER));
        $this->assertTrue($roles->addUser($projectId, $member['id'], Role::PROJECT_MEMBER));
        $ctx = ['project_id' => $projectId] + self::BOARD;

        $viewerIds = array_column($registry->permitted($viewer, $ctx), 'id');
        $memberIds = array_column($registry->permitted($member, $ctx), 'id');
        $this->assertNotContains('switchboard.core.new-task', $viewerIds);
        $this->assertContains('switchboard.core.new-task', $memberIds);
        $this->assertSame(['switchboard.core.new-task'], array_values(array_diff($memberIds, $viewerIds)));
    }

    public function testCoreDescriptorsHaveValidIdsAndDisplayMetadata(): void
    {
        $commands = CoreCommands::forContext(self::BOARD);
        $this->assertCount(10, $commands);
        foreach ($commands as $command) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(\.[a-z0-9_-]+)+$/D', $command['id']);
            $this->assertStringStartsWith('switchboard.core.', $command['id']);
            $this->assertSame('Kanboard', $command['group']);
            $this->assertSame('read', $command['risk']);
        }
    }

    public function testForContextMakesNoDatabaseCalls(): void
    {
        $db = $this->container['db'];
        $proxy = new class($db) {
            public int $calls = 0;

            public function __construct(private Database $db)
            {
            }

            public function __call(string $method, array $args): mixed
            {
                $this->calls++;
                return $this->db->$method(...$args);
            }
        };
        unset($this->container['db']);
        $this->container['db'] = $proxy;

        CoreCommands::forContext([]);
        CoreCommands::forContext(self::BOARD);

        $this->assertSame(0, $proxy->calls);
    }
}
