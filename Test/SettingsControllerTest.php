<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Middleware\ApplicationAuthorizationMiddleware;
use Kanboard\Plugin\Switchboard\Plugin;
use KanboardTests\units\Base;

final class SettingsControllerTest extends Base
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container['hook'] = new Hook();
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();
    }

    public function testSaveStoresOffAndClearEmptiesOnlyTheCurrentUsersRecents(): void
    {
        $this->login(Role::APP_USER);
        $frecency = $this->container['switchboardFrecency'];
        $frecency->record($this->userId, 'example.recent');
        $frecency->record(1, 'example.other');

        $this->call('/switchboard/settings/save', ['single_keys' => '0']);
        $this->assertSame('0', $this->container['userMetadataModel']->getAll($this->userId)['switchboard.single_keys']);
        $this->assertSame(['example.recent'], $frecency->recent($this->userId));
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="single_keys" value="1"\s*>/', $this->call('/switchboard/settings'));

        $this->call('/switchboard/settings/save', ['clear' => '1']);
        $this->assertSame('0', $this->container['userMetadataModel']->getAll($this->userId)['switchboard.single_keys']);
        $this->assertSame([], $frecency->recent($this->userId));
        $this->assertSame(['example.other'], $frecency->recent(1));
    }

    public function testBothSavesRequireCsrf(): void
    {
        $this->login(Role::APP_ADMIN);
        foreach (['/switchboard/settings/save', '/switchboard/admin/save'] as $path) {
            try {
                $this->call($path, [], false);
                $this->fail('Settings accepted without CSRF');
            } catch (AccessForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAccessMapDeniesManagerAdminSave(): void
    {
        $this->login(Role::APP_MANAGER);
        $authorization = $this->container['applicationAuthorization'];
        $route = $this->container['route']->findRoute('switchboard/admin/save');
        $this->assertFalse($authorization->isAllowed($route['controller'], $route['action'], Role::APP_MANAGER));
        $this->assertTrue($authorization->isAllowed($route['controller'], $route['action'], Role::APP_ADMIN));

        $route = $this->container['route']->findRoute('switchboard/admin');
        $this->assertFalse($authorization->isAllowed($route['controller'], $route['action'], Role::APP_MANAGER));
    }

    public function testUserCanPostSettingsWhenAnotherPluginRestrictsSettingsControllerSave(): void
    {
        $this->login(Role::APP_USER);
        $this->container['applicationAccessMap']->add('SettingsController', ['save'], Role::APP_ADMIN);

        $this->call('/switchboard/settings/save', ['single_keys' => '0']);

        $this->assertSame('0', $this->container['userMetadataModel']->getAll($this->userId)['switchboard.single_keys']);
    }

    public function testAdminDisplaysAndSavesOnlyRegisteredExternalProviders(): void
    {
        $this->login(Role::APP_ADMIN);
        $this->provider('example.external', 'External example', true);
        $this->provider('example.internal', 'Internal example', false);
        $this->provider('example.internal', 'Duplicate example', true);
        $html = $this->call('/switchboard/admin');
        $this->assertStringContainsString('External example', $html);
        $this->assertStringNotContainsString('Internal example', $html);
        $this->assertStringNotContainsString('Duplicate example', $html);

        $this->call('/switchboard/admin/save', ['providers' => [
            'example.external', 'evil.provider', 'example.internal', 'switchboard.tasks', 'example.external',
        ]]);
        $this->assertSame('["example.external"]', $this->container['configModel']->getOption('switchboard_external_providers'));

        $this->call('/switchboard/admin/save', []);
        $this->assertSame('[]', $this->container['configModel']->getOption('switchboard_external_providers'));
    }

    private function provider(string $id, string $title, bool $external): void
    {
        $this->container['hook']->on('switchboard:search', static fn(): array => [[
            'id' => $id,
            'title' => $title,
            'external' => $external,
            'order' => static fn(): int => 10,
            'search' => static fn(): array => [],
        ]]);
    }

    private function login(string $role): void
    {
        $users = $this->container['userModel'];
        $this->userId = $users->create(['username' => 'settings-user', 'role' => $role]);
        $this->container['userSession']->initialize($users->getById($this->userId));
    }

    private function call(string $path, ?array $post = null, bool $csrf = true): string
    {
        if ($post !== null && $csrf) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        $this->container['request'] = new Request(
            $this->container,
            [
                'REQUEST_METHOD' => $post === null ? 'GET' : 'POST',
                'REQUEST_URI' => $path,
                'PHP_SELF' => '/index.php',
            ],
            [],
            $post ?? []
        );
        $html = '';
        $response = $this->createMock(Response::class);
        $response->method('html')->willReturnCallback(static function (string $body) use (&$html): void {
            $html = $body;
        });
        $response->method('withStatusCode')->willReturnSelf();
        $this->container['response'] = $response;

        $router = $this->container['router'];
        $router->dispatch();
        (new ApplicationAuthorizationMiddleware($this->container))->execute();
        $controller = 'Kanboard\\Plugin\\'.$router->getPlugin().'\\Controller\\'.$router->getController();
        (new $controller($this->container))->{$router->getAction()}();

        return $html;
    }
}
