<?php

use Composer\Autoload\ClassLoader;
use Kanboard\Core\Http\Route;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Helper\UrlHelper;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\Switchboard\Model\Registry;
use Kanboard\Plugin\Switchboard\Plugin;
use PHPUnit\Framework\TestCase;
use Pimple\Container;
use Psr\Log\LoggerInterface;

final class RegistryTest extends TestCase
{
    private const CONTEXT = [
        'user_id' => 7,
        'route' => ['controller' => 'DashboardController', 'action' => 'show', 'plugin' => ''],
        'project_id' => null,
        'task_id' => null,
    ];

    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container['hook'] = new Hook();
        $this->container['logger'] = $this->createMock(LoggerInterface::class);
    }

    public function testPluginRegistersRegistryAsFactory(): void
    {
        (new Plugin($this->container))->initialize();

        $registry = $this->container['switchboardRegistry'];
        $this->assertInstanceOf(Registry::class, $registry);
        $this->assertNotSame($registry, $this->container['switchboardRegistry']);
    }

    public function testValidationKeepsFirstRegistrationAndLogsEachRejection(): void
    {
        $first = $this->command('acme.first', ['title' => 'First registration']);
        $this->container['hook']->on('switchboard:commands', static fn(array $ctx) => $ctx === self::CONTEXT ? [$first] : []);
        $this->listen([
            $this->command('create'),
            $this->command('acme.first', ['title' => 'Second registration']),
            array_diff_key($this->command('acme.untitled'), ['title' => true]),
            $this->command('acme.risk', ['risk' => 'dangerous']),
            $this->command('acme.target', ['target' => ['kind' => 'other']]),
            $this->command('acme.string-handler', ['target' => ['kind' => 'handler', 'handler' => 'strval']]),
            $this->command('acme.input', ['input' => [
                'type' => 'object',
                'properties' => array_fill_keys(['a', 'b', 'c', 'd'], ['type' => 'string']),
            ]]),
            $this->command('acme.foo', ['target' => ['kind' => 'key', 'key' => 'b']]),
            $this->command('switchboard.core.foo', ['target' => ['kind' => 'key', 'key' => 'b']]),
        ]);
        $logged = $this->recordWarnings(8);

        $commands = (new Registry($this->container))->all(self::CONTEXT);

        $this->assertSame(['acme.first', 'switchboard.core.foo'], array_column($commands, 'id'));
        $this->assertSame('First registration', $commands[0]['title']);
        $this->assertSame([
            'Switchboard: skipped command "create" (invalid id)',
            'Switchboard: skipped command "acme.first" (duplicate id)',
            'Switchboard: skipped command "acme.untitled" (missing title)',
            'Switchboard: skipped command "acme.risk" (invalid risk)',
            'Switchboard: skipped command "acme.target" (invalid target)',
            'Switchboard: skipped command "acme.string-handler" (invalid target)',
            'Switchboard: skipped command "acme.input" (invalid input schema)',
            'Switchboard: skipped command "acme.foo" (invalid target)',
        ], $logged->getArrayCopy());
    }

    public function testBrokenListenersAreLoggedAndOtherListenersSurvive(): void
    {
        $this->container['hook']->on('switchboard:commands', static fn() => throw new RuntimeException('private'));
        $this->container['hook']->on('switchboard:commands', static fn(): string => 'garbage');
        $this->listen([$this->command('acme.survivor')]);
        $logged = $this->recordWarnings(2);

        $commands = (new Registry($this->container))->all(self::CONTEXT);

        $this->assertSame(['acme.survivor'], array_column($commands, 'id'));
        $this->assertSame(array_fill(0, 2, 'Switchboard: skipped command "" (listener failed)'), $logged->getArrayCopy());
    }

    public function testAllNormalizesAndSortsByGroupThenTitleIgnoringCase(): void
    {
        $this->listen([
            $this->command('acme.last', ['group' => 'Zoo', 'title' => 'A']),
            $this->command('acme.third'),
            $this->command('acme.second', ['group' => 'a', 'title' => 'Zoo']),
            $this->command('acme.first', ['group' => 'A', 'title' => 'apple']),
        ]);

        $commands = (new Registry($this->container))->all(self::CONTEXT);

        $this->assertSame(['acme.first', 'acme.second', 'acme.third', 'acme.last'], array_column($commands, 'id'));
        $defaults = ['group' => 'General', 'keywords' => [], 'icon' => 'command', 'risk' => 'read',
            'step_up' => false, 'requires_ai' => false, 'input' => null];
        $this->assertEquals($defaults, array_intersect_key($commands[2], $defaults));
    }

    public function testToClientKeepsPublicTargetDataAndUsesRawUrl(): void
    {
        $url = '/?controller=BoardViewController&action=show&project_id=15';
        $route = $this->createMock(Route::class);
        $route->expects($this->exactly(3))->method('findUrl')
            ->with('BoardViewController', 'show', ['project_id' => 15])
            ->willReturn('');
        $this->container['route'] = $route;
        $urlHelper = $this->getMockBuilder(UrlHelper::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['dir'])
            ->getMock();
        $urlHelper->method('dir')->willReturn('/');
        $this->container['helper'] = (object) ['url' => $urlHelper];
        $registry = new Registry($this->container);
        $handler = static fn(): array => [];
        $this->listen([$this->command('acme.handler', [
            'permission' => static fn(): bool => true,
            'target' => ['kind' => 'handler', 'handler' => $handler],
        ])]);
        $descriptor = $registry->all(self::CONTEXT)[0];
        $this->assertSame($handler, $descriptor['target']['handler']);
        $client = $registry->toClient($descriptor);
        $this->assertArrayNotHasKey('permission', $client);
        $this->assertSame(['kind' => 'handler'], $client['target']);

        foreach (['route', 'modal', 'post'] as $kind) {
            $target = ['kind' => $kind, 'controller' => 'BoardViewController', 'action' => 'show',
                'params' => ['project_id' => 15]];
            $expected = ['kind' => $kind];
            if ($kind === 'modal') {
                $target['size'] = $expected['size'] = 'large';
            }
            $client = $registry->toClient($this->command('acme.link', ['target' => $target]));
            $this->assertSame($expected, $client['target']);
            $this->assertSame($url, $client['url']);
        }

        $key = ['kind' => 'key', 'key' => 'v+b'];
        $this->assertSame($key, $registry->toClient($this->command('switchboard.core.board', ['target' => $key]))['target']);
    }

    public function testPermittedAndFindUsePermissions(): void
    {
        $this->listen([
            $this->command('acme.hidden', ['permission' => static fn(): bool => false]),
            $this->command('acme.visible'),
        ]);

        $registry = new Registry($this->container);
        $user = ['id' => 7, 'role' => 'app-user'];

        $this->assertSame(['acme.visible'], array_column($registry->permitted($user, self::CONTEXT), 'id'));
        $this->assertNull($registry->find('acme.hidden', $user, self::CONTEXT));
        $this->assertSame('acme.visible', $registry->find('acme.visible', $user, self::CONTEXT)['id']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAiCommandsAreHiddenWhenConnectorIsAbsent(): void
    {
        // The shared harness can have AiConnector installed alongside Switchboard.
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->setPsr4('Kanboard\Plugin\AiConnector\\', []);
        }
        $this->assertFalse(class_exists(ProviderRegistry::class));
        $this->listen([$this->command('acme.ai', ['requires_ai' => true])]);

        $registry = new Registry($this->container);
        $user = ['id' => 7, 'role' => 'app-user'];
        $this->assertSame(['acme.ai'], array_column($registry->all(self::CONTEXT), 'id'));
        $this->assertSame([], $registry->permitted($user, self::CONTEXT));
        $this->assertNull($registry->find('acme.ai', $user, self::CONTEXT));
    }

    private function command(string $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'title' => $id,
            'target' => ['kind' => 'handler', 'handler' => static fn(): array => []],
        ];
    }

    private function listen(array $commands): void
    {
        $this->container['hook']->on('switchboard:commands', static fn(): array => $commands);
    }

    private function recordWarnings(int $count): ArrayObject
    {
        $messages = new ArrayObject();
        $this->container['logger']->expects($this->exactly($count))->method('warning')
            ->willReturnCallback(static function (string $message) use ($messages): void {
                $messages[] = $message;
            });

        return $messages;
    }
}
