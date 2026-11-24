<?php

use Kanboard\Core\Http\Request;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Plugin\Loader;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Model\Executor;
use Kanboard\Plugin\Switchboard\Plugin;
use KanboardTests\units\Base;
use Psr\Log\LoggerInterface;

final class ExecutorTest extends Base
{
    private const ID = 'test.command';
    private const TOAST = ['type' => 'toast', 'message' => 'Saved'];
    private array $user;
    private int $now = 1_800_000_000;
    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        (new Loader($this->container))->loadSchema('Switchboard');

        $this->container['hook'] = new Hook();
        $this->container['request'] = new Request($this->container);
        $this->container['logger'] = $this->createMock(LoggerInterface::class);
        (new Plugin($this->container))->initialize();
        $id = $this->container['userModel']->create(['username' => 'caller']);
        $this->user = ['id' => $id, 'role' => Role::APP_USER];
    }

    public function testPluginRegistersExecutor(): void
    {
        $this->assertInstanceOf(Executor::class, $this->container['switchboardExecutor']);
    }

    public function testRunForbiddenCommandDoesNotCallHandler(): void
    {
        $this->register(['permission' => Role::APP_ADMIN]);
        $this->assertSame(['status' => 403, 'body' => ['error' => 'forbidden']], $this->execute());
        $this->assertSame(0, $this->calls);
        $this->assertSame([], $this->recentUses());
    }

    public function testUnknownCommandReturns404(): void
    {
        $this->assertSame(['status' => 404, 'body' => ['error' => 'unknown command']], $this->execute());
        $this->assertSame([], $this->recentUses());
    }

    public function testInvalidAndInaccessibleProjectInputsReturnTheField(): void
    {
        $other = $this->container['userModel']->create(['username' => 'owner']);
        $private = $this->container['projectModel']->create(['name' => 'Private', 'is_private' => 1]);
        $this->container['projectUserRoleModel']->addUser($private, $other, Role::PROJECT_MANAGER);
        $this->register(['input' => self::schema(['format' => 'project'])]);

        $expected = ['status' => 422, 'body' => ['error' => 'invalid input', 'field' => 'value']];
        $this->assertSame($expected, $this->execute());
        $this->assertSame($expected, $this->execute(['value' => (string) $private]));
        $this->assertSame(0, $this->calls);
        $this->assertSame([], $this->recentUses());
    }

    public function testReversibleHandlerReceivesCleanInputAndRecordsOneUse(): void
    {
        $this->register(['risk' => 'reversible', 'input' => self::schema(), 'target' => [
            'kind' => 'handler',
            'handler' => function (array $user, array $ctx, array $input): array {
                $this->calls++;
                $this->assertSame($this->user, $user);
                $this->assertSame(['user_id' => $user['id']], $ctx);
                $this->assertSame(['value' => 'one'], $input);

                return self::TOAST;
            },
        ]]);

        $this->assertSame(['status' => 200, 'body' => self::TOAST], $this->execute(['value' => 'one', 'extra' => 'drop']));
        $this->assertSame(1, $this->calls);
        $this->assertSame([['command_id' => self::ID, 'count' => 1, 'last_used' => $this->now]], $this->recentUses());
    }

    public function testConsequentialCommandRequiresConfirmationBoundToInputUserAndExpiry(): void
    {
        $this->register(['risk' => 'consequential', 'input' => self::schema()]);
        $input = ['value' => 'first'];
        $response = $this->execute($input);
        $this->assertSame(200, $response['status']);
        $confirm = $response['body']['confirm'];
        $this->assertStringContainsString('Run "Test command"?', $confirm['message']);
        $this->assertStringContainsString('first', $confirm['message']);
        $this->assertSame(0, $this->calls);
        $this->assertSame([], $this->recentUses());

        $secret = $this->container['configModel']->getOption('switchboard_secret');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $token = $confirm['token'];
        $invalid = ['status' => 409, 'body' => ['error' => 'invalid confirm']];
        $this->assertSame($invalid, $this->execute(['value' => 'second'], $token));
        $this->assertSame([], $this->recentUses());

        $other = $this->container['userModel']->create(['username' => 'other']);
        $this->assertSame($invalid, $this->execute($input, $token, ['id' => $other, 'role' => Role::APP_USER]));
        $this->assertSame([], $this->recentUses($other));
        $this->assertSame(0, $this->calls);
        $this->assertSame(['status' => 200, 'body' => self::TOAST], $this->execute($input, $token));
        $this->assertSame(1, $this->calls);
        $recorded = [['command_id' => self::ID, 'count' => 1, 'last_used' => $this->now]];
        $this->assertSame($recorded, $this->recentUses());
        $this->now += 121;
        $this->assertSame($invalid, $this->execute($input, $token));
        $this->assertSame(1, $this->calls);
        $this->assertSame($recorded, $this->recentUses());
        $this->execute($input);
        $this->assertSame($secret, $this->container['configModel']->getOption('switchboard_secret'));
    }

    public function testConfirmationIsBoundToTheProjectAndTaskContext(): void
    {
        $this->register(['risk' => 'consequential']);
        $ctx = ['project_id' => 5, 'task_id' => 1];
        $token = $this->execute(ctx: $ctx)['body']['confirm']['token'];
        $invalid = ['status' => 409, 'body' => ['error' => 'invalid confirm']];

        $this->assertSame($invalid, $this->execute(token: $token, ctx: ['task_id' => 2] + $ctx));
        $this->assertSame($invalid, $this->execute(token: $token, ctx: ['project_id' => 6] + $ctx));
        $this->assertSame(0, $this->calls);
        $this->assertSame([], $this->recentUses());
        $this->assertSame(['status' => 200, 'body' => self::TOAST], $this->execute(token: $token, ctx: $ctx));
        $this->assertSame(1, $this->calls);
    }

    public function testStepUpConsultsTheFirstAdapterBeforeConfirmation(): void
    {
        $this->register(['step_up' => true]);
        $approved = false;
        $this->container['hook']->on('switchboard:host', [
            'name' => 'Host',
            'step_up' => function (array $user) use (&$approved): array {
                $this->assertSame($this->user, $user);

                return $approved ? ['ok' => true] : ['ok' => false, 'url' => '/step-up'];
            },
        ]);
        $this->container['hook']->on('switchboard:host', [
            'step_up' => fn() => $this->fail('Only the first adapter should run'),
        ]);

        $this->assertSame(['status' => 428, 'body' => ['error' => 'step up required', 'url' => '/step-up']], $this->execute());
        $this->assertSame([], $this->recentUses());
        $approved = true;
        $this->assertArrayHasKey('confirm', $this->execute()['body']);
        $this->assertSame(0, $this->calls);
        $this->assertSame([], $this->recentUses());
    }

    public function testStepUpWithoutAdapterRequiresConfirmation(): void
    {
        $this->register(['step_up' => true]);

        $this->assertArrayHasKey('confirm', $this->execute()['body']);
        $this->assertSame(0, $this->calls);
        $this->assertSame([], $this->recentUses());
    }

    /** @dataProvider invalidHostAdapters */
    public function testInvalidHostAdapterReturnsGenericErrorAndLogsWarning(array $adapter): void
    {
        $this->register(['step_up' => true]);
        $this->container['hook']->on('switchboard:host', $adapter);
        $this->container['logger']->expects($this->once())->method('warning')
            ->with('Switchboard: command "test.command" failed (InvalidHostAdapter)');

        $this->assertSame(['status' => 500, 'body' => ['error' => 'command failed']], $this->execute());
        $this->assertSame(0, $this->calls);
        $this->assertSame([], $this->recentUses());
    }

    public static function invalidHostAdapters(): array
    {
        return [
            'non-callable step-up' => [['step_up' => false]],
            'missing URL when denied' => [['step_up' => static fn(): array => ['ok' => false]]],
            'empty URL' => [['step_up' => static fn(): array => ['ok' => false, 'url' => '']]],
            'non-string URL' => [['step_up' => static fn(): array => ['ok' => false, 'url' => 123]]],
            'invalid URL when approved' => [['step_up' => static fn(): array => ['ok' => true, 'url' => null]]],
            'missing decision' => [['step_up' => static fn(): array => ['url' => '/step-up']]],
        ];
    }

    public function testHandlerExceptionReturnsGenericErrorAndLogsOnlyClass(): void
    {
        $this->register(['target' => [
            'kind' => 'handler',
            'handler' => static fn() => throw new RuntimeException('secret-msg'),
        ]]);
        $this->container['logger']->expects($this->once())->method('warning')
            ->with('Switchboard: command "test.command" failed (RuntimeException)');

        $this->assertSame(['status' => 500, 'body' => ['error' => 'command failed']], $this->execute());
        $this->assertSame([], $this->recentUses());
    }

    /** The command already ran: a failure to record the use is logged, not reported as a failed command. */
    public function testRecordingFailureAfterASuccessfulCommandStillReportsItsResult(): void
    {
        $this->register([]);
        $this->container['db']->getConnection()->exec('DROP TABLE switchboard_recent');
        $this->container['logger']->expects($this->once())->method('warning')
            ->with($this->stringStartsWith('Switchboard: recording "test.command" failed ('));

        $this->assertSame(['status' => 200, 'body' => self::TOAST], $this->execute());
        $this->assertSame(1, $this->calls);
    }

    public function testUnknownResultTypeFails(): void
    {
        $this->register(['target' => ['kind' => 'handler', 'handler' => static fn(): array => ['type' => 'weird']]]);

        $this->assertSame(['status' => 500, 'body' => ['error' => 'command failed']], $this->execute());
        $this->assertSame([], $this->recentUses());
    }

    public function testListResultsAreCappedAtTenItems(): void
    {
        $item = ['title' => 'Result', 'url' => '/result'];
        $this->register(['target' => [
            'kind' => 'handler',
            'handler' => static fn(): array => ['type' => 'list', 'items' => array_fill(0, 11, $item)],
        ]]);

        $this->assertSame(['status' => 200, 'body' => ['type' => 'list', 'items' => array_fill(0, 10, $item)]], $this->execute());
    }

    public function testRouteReturnsNavigationHref(): void
    {
        $this->register(['target' => ['kind' => 'route', 'controller' => 'DashboardController', 'action' => 'show']]);

        $this->assertSame([
            'status' => 200,
            'body' => ['type' => 'navigate', 'url' => '?controller=DashboardController&action=show'],
        ], $this->execute());
    }

    private function recentUses(?int $userId = null): array
    {
        return $this->container['db']->table('switchboard_recent')
            ->eq('user_id', $userId ?? $this->user['id'])
            ->columns('command_id', 'count', 'last_used')
            ->findAll();
    }

    private function execute(array $input = [], ?string $token = null, ?array $user = null, array $ctx = []): array
    {
        $user ??= $this->user;
        $executor = new Executor($this->container, fn(): int => $this->now);

        return $executor->run($user, ['user_id' => $user['id']] + $ctx, self::ID, $input, $token);
    }

    private function register(array $descriptor): void
    {
        $this->container['hook']->on('switchboard:commands', fn(): array => [$descriptor + [
            'id' => self::ID,
            'title' => 'Test command',
            'target' => ['kind' => 'handler', 'handler' => function (): array {
                $this->calls++;
                return self::TOAST;
            }],
        ]]);
    }

    private static function schema(array $rules = []): array
    {
        return [
            'type' => 'object',
            'properties' => ['value' => ['type' => 'string'] + $rules],
            'required' => ['value'],
        ];
    }
}
