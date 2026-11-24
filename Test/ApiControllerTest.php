<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Controller\ApiController;
use Kanboard\Plugin\Switchboard\Plugin;
use KanboardTests\units\Base;
use Psr\Log\LoggerInterface;

final class ApiControllerTest extends Base
{
    private const TOAST = ['type' => 'toast', 'message' => 'Done'];

    private int $alice;
    private int $handlerCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container['hook'] = new Hook();
        $this->container['logger'] = $this->createMock(LoggerInterface::class);
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();

        $users = $this->container['userModel'];
        $this->alice = $users->create(['username' => 'alice', 'role' => Role::APP_USER]);
        $this->container['userSession']->initialize($users->getById($this->alice));
    }

    public function testRoutesAreRegistered(): void
    {
        $routes = [
            'switchboard' => ['PageController', 'index'],
            'switchboard/confirm' => ['PageController', 'confirm'],
            'switchboard/commands' => ['ApiController', 'commands'],
            'switchboard/providers' => ['ApiController', 'providers'],
            'switchboard/search' => ['ApiController', 'search'],
            'switchboard/run' => ['ApiController', 'run'],
            'switchboard/used' => ['ApiController', 'used'],
            'switchboard/settings' => ['SettingsController', 'show'],
            'switchboard/settings/save' => ['SettingsController', 'save'],
            'switchboard/admin' => ['SettingsController', 'admin'],
            'switchboard/admin/save' => ['SettingsController', 'adminSave'],
        ];

        foreach ($routes as $path => [$controller, $action]) {
            $this->assertSame($path, $this->container['route']->findUrl($controller, $action, ['plugin' => 'Switchboard']));
        }
    }

    public function testCommandsReturnsPermittedClientDescriptorsAndRecent(): void
    {
        $this->command('example.visible');
        $this->command('example.hidden', Role::APP_ADMIN);
        $this->container['switchboardFrecency']->record($this->alice, 'example.visible');

        [$status, $body] = $this->call('commands');

        $this->assertSame(200, $status);
        $this->assertSame(['example.visible'], array_column($body['commands'], 'id'));
        $this->assertSame(['kind' => 'handler'], $body['commands'][0]['target']);
        $this->assertSame(['example.visible'], $body['recent']);
    }

    public function testContextDropsInaccessibleIdsAndBoundsRoute(): void
    {
        $bob = $this->container['userModel']->create(['username' => 'bob']);
        $project = $this->container['projectModel']->create(['name' => 'Bob private project', 'is_private' => 1]);
        $this->assertTrue($this->container['projectUserRoleModel']->addUser($project, $bob, Role::PROJECT_MANAGER));
        $task = $this->container['taskCreationModel']->create(['project_id' => $project, 'title' => 'Bob task', 'owner_id' => $bob]);
        $this->assertGreaterThan(0, $task);

        $contexts = [];
        $this->container['hook']->on('switchboard:commands', static function (array $ctx) use (&$contexts): array {
            $contexts[] = $ctx;

            return [];
        });

        $query = [
            'task_id' => (string) $task,
            'project_id' => (string) $project,
            'from_controller' => str_repeat('c', 65),
            'from_action' => ['bad'],
            'from_plugin' => 'Example',
        ];
        $this->call('commands', $query);
        $this->assertTrue($this->container['projectUserRoleModel']->addUser($project, $this->alice, Role::PROJECT_VIEWER));
        $this->container['memoryCache']->flush();
        $this->call('commands', $query);

        $this->assertCount(2, $contexts);
        $this->assertNull($contexts[0]['task_id']);
        $this->assertNull($contexts[0]['project_id']);
        $this->assertSame($task, $contexts[1]['task_id']);
        $this->assertSame($project, $contexts[1]['project_id']);
        $this->assertSame($this->alice, $contexts[0]['user_id']);
        $this->assertSame([
            'controller' => str_repeat('c', 64),
            'action' => '',
            'plugin' => 'Example',
        ], $contexts[0]['route']);
    }

    public function testProvidersOmitsSensitiveProviderWithoutItsPrefix(): void
    {
        $this->provider('vault.secrets', static fn(): array => [], true);

        [$status, $body] = $this->call('providers');
        $this->assertSame(200, $status);
        $this->assertSame(['switchboard.tasks', 'switchboard.projects', 'switchboard.users'], array_column($body['providers'], 'id'));

        [$status, $body] = $this->call('providers', ['prefix' => '!']);
        $this->assertSame(200, $status);
        $this->assertContains('vault.secrets', array_column($body['providers'], 'id'));
    }

    public function testSearchPassesPagingArgumentsAndLeavesSessionUnchanged(): void
    {
        $calls = [];
        $this->provider('example.search', static function (array $user, string $term, ?string $cursor, int $limit) use (&$calls): array {
            $calls[] = [$user['id'], $term, $cursor, $limit];

            return ['entries' => [['title' => 'Match', 'url' => '/match']], 'cursor' => 'next'];
        });

        $before = serialize($_SESSION);
        $query = ['provider' => 'example.search', 'term' => 'match', 'cursor' => 'first'];
        $this->assertSame([200, [
            'entries' => [['title' => 'Match', 'subline' => '', 'url' => '/match', 'icon' => '']],
            'cursor' => 'next',
        ]], $this->call('search', $query + ['limit' => '99']));
        $this->call('search', $query + ['limit' => '-3']);

        $this->assertSame([[$this->alice, 'match', 'first', 10], [$this->alice, 'match', 'first', 1]], $calls);
        $this->assertSame($before, serialize($_SESSION));
    }

    public function testSearchReturnsGenericErrors(): void
    {
        $this->provider('example.broken', static fn() => throw new RuntimeException('private provider detail'));
        $this->assertSame([404, ['error' => 'unknown provider']], $this->call('search', ['provider' => 'example.missing']));
        $this->assertSame([500, ['error' => 'provider failed']], $this->call('search', ['provider' => 'example.broken']));
    }

    public function testSensitiveSearchRequiresItsPrefixInGlobalContext(): void
    {
        $this->provider('vault.secrets', static fn(): array => [
            'entries' => [['title' => 'Secret', 'url' => '/vault', 'subline' => 'Private detail']],
        ], true);

        $query = ['provider' => 'vault.secrets'];
        $this->assertSame([404, ['error' => 'unknown provider']], $this->call('search', $query));
        $this->assertSame([200, [
            'entries' => [['title' => 'Secret', 'url' => '/vault']], 'cursor' => null,
        ]], $this->call('search', $query + ['prefix' => '!']));
    }

    public function testRunWithoutCsrfDoesNotCallHandler(): void
    {
        $this->command('example.visible');
        $this->expectException(AccessForbiddenException::class);

        try {
            $this->call('run', post: ['id' => 'example.visible'], csrf: false);
        } finally {
            $this->assertSame(0, $this->handlerCalls);
        }
    }

    public function testRunReturnsExecutorStatusAndBody(): void
    {
        $this->command('example.visible');
        $this->command('example.hidden', Role::APP_ADMIN);

        $this->assertSame([200, self::TOAST], $this->call('run', post: ['id' => 'example.visible']));
        $this->assertSame([403, ['error' => 'forbidden']], $this->call('run', post: ['id' => 'example.hidden']));
        $this->assertSame(1, $this->handlerCalls);
    }

    public function testRunRejectsMalformedInputWithoutExecuting(): void
    {
        $this->command('example.visible');

        foreach (['{', '[]', ['unexpected']] as $input) {
            $this->assertSame([422, ['error' => 'invalid input', 'field' => 'input']], $this->call(
                'run', post: ['id' => 'example.visible', 'input' => $input]
            ));
        }

        $this->assertSame(0, $this->handlerCalls);
    }

    public function testUsedOnlyRecordsPermittedIdsWithoutRunningHandler(): void
    {
        $this->command('example.visible');
        $this->command('example.hidden', Role::APP_ADMIN);
        $frecency = $this->container['switchboardFrecency'];

        $this->assertSame([204, null], $this->call('used', post: ['id' => 'example.hidden']));
        $this->assertSame([], $frecency->recent($this->alice));
        $this->assertSame([204, null], $this->call('used', post: ['id' => 'example.visible']));
        $this->assertSame(['example.visible'], $frecency->recent($this->alice));
        $this->assertSame(0, $this->handlerCalls);
    }

    private function call(string $action, array $query = [], ?array $post = null, bool $csrf = true): array
    {
        if ($post !== null && $csrf) {
            $post['csrf_token'] = $this->container['token']->getReusableCSRFToken();
        }
        $this->container['request'] = new Request(
            $this->container,
            ['REQUEST_METHOD' => $post === null ? 'GET' : 'POST'],
            $query + ['_' => '1'],
            $post ?? [],
        );

        $result = null;
        $response = $this->createMock(Response::class);
        $response->method('json')->willReturnCallback(static function (array $body, int $status = 200) use (&$result): void {
            $result = [$status, $body];
        });
        $response->method('status')->willReturnCallback(static function (int $status) use (&$result): void {
            $result = [$status, null];
        });
        $this->container['response'] = $response;

        (new ApiController($this->container))->$action();

        return $result;
    }

    private function command(string $id, string $permission = Role::APP_USER): void
    {
        $this->container['hook']->on('switchboard:commands', fn(): array => [[
            'id' => $id,
            'title' => $id,
            'permission' => $permission,
            'target' => ['kind' => 'handler', 'handler' => function (): array {
                $this->handlerCalls++;

                return self::TOAST;
            }],
        ]]);
    }

    private function provider(string $id, callable $search, bool $sensitive = false): void
    {
        $this->container['hook']->on('switchboard:search', static fn(): array => [[
            'id' => $id,
            'title' => $id,
            'order' => static fn(): int => 40,
            'search' => $search,
            'sensitive' => $sensitive,
            'prefix' => '!',
        ]]);
    }
}
