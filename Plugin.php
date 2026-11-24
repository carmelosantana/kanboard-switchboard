<?php

namespace Kanboard\Plugin\Switchboard;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Command\CoreCommands;
use Kanboard\Plugin\Switchboard\Model\Executor;
use Kanboard\Plugin\Switchboard\Model\Frecency;
use Kanboard\Plugin\Switchboard\Model\Providers;
use Kanboard\Plugin\Switchboard\Model\Registry;
use Kanboard\Plugin\Switchboard\Provider\Projects;
use Kanboard\Plugin\Switchboard\Provider\Tasks;
use Kanboard\Plugin\Switchboard\Provider\Users;
use Pimple\Container;

class Plugin extends Base
{
    public function initialize(): void
    {
        if (isset($this->container['route'])) {
            $this->registerRoutes();
        }

        $this->container['switchboardRegistry'] = $this->container->factory(
            static fn(Container $c): Registry => new Registry($c)
        );
        $this->container['switchboardProviders'] = $this->container->factory(
            static fn(Container $c): Providers => new Providers($c)
        );
        $this->container['switchboardFrecency'] = $this->container->factory(
            static fn(Container $c): Frecency => new Frecency($c)
        );
        $this->container['switchboardExecutor'] = $this->container->factory(
            static fn(Container $c): Executor => new Executor($c)
        );

        if (isset($this->container['applicationAccessMap'])) {
            $this->applicationAccessMap->add('SwitchboardSettingsController', ['admin', 'adminSave'], Role::APP_ADMIN);
        }
        $this->hook->on('template:header:dropdown', [
            'template' => 'Switchboard:header/link',
            'callable' => $this->headerContext(...),
        ]);
        $this->hook->on('template:user:sidebar:actions', ['template' => 'Switchboard:user/sidebar']);
        $this->hook->on('template:config:sidebar', ['template' => 'Switchboard:config/sidebar']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/Switchboard/Assets/css/switchboard.css']);
        foreach (['switchboard-core.js', 'switchboard.js'] as $script) {
            $this->hook->on('template:layout:js', ['template' => 'plugins/Switchboard/Assets/js/'.$script]);
        }
        $this->hook->on('template:layout:bottom', [
            'template' => 'Switchboard:layout/root',
            'callable' => $this->layoutContext(...),
        ]);

        $this->hook->on('switchboard:commands', CoreCommands::forContext(...));

        $builtins = [
            ['switchboard.tasks', 'Tasks', '#', 10, new Tasks($this->container)],
            ['switchboard.projects', 'Projects', ':', 20, new Projects($this->container)],
            ['switchboard.users', 'Users', '@', 30, new Users($this->container)],
        ];
        foreach ($builtins as [$id, $title, $prefix, $order, $provider]) {
            $this->hook->on('switchboard:search', static fn(array $ctx): array => [[
                'id' => $id,
                'title' => $title,
                'prefix' => $prefix,
                'order' => static fn(array $ctx): int => $order,
                'permission' => Role::APP_USER,
                'search' => $provider->search(...),
            ]]);
        }
    }

    public function getPluginName(): string
    {
        return 'Switchboard';
    }

    public function getPluginDescription(): string
    {
        return 'Command palette for Kanboard: press Ctrl/⌘+K to find and run any action, with commands and search added by plugins.';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-switchboard';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }

    private function layoutContext(): array
    {
        if (!$this->userSession->isLogged()) {
            return [];
        }
        // get() treats the stored string "0" as empty.
        $metadata = $this->userMetadataModel->getAll($this->userSession->getId());

        return $this->headerContext() + [
            'switchboardSingleKeys' => ($metadata['switchboard.single_keys'] ?? '1') === '1' ? '1' : '0',
        ];
    }

    private function headerContext(): array
    {
        $context = array_filter([
            'from_controller' => $this->router->getController(),
            'from_action' => $this->router->getAction(),
            'from_plugin' => $this->router->getPlugin(),
        ], static fn(string $value): bool => $value !== '');
        foreach (['project_id', 'task_id'] as $key) {
            $id = $this->request->getStringParam($key);
            if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
                $context[$key] = $id;
            }
        }

        return ['switchboardContext' => $context];
    }

    private function registerRoutes(): void
    {
        $routes = [
            'switchboard' => ['SwitchboardPageController', 'index'],
            'switchboard/confirm' => ['SwitchboardPageController', 'confirm'],
            'switchboard/commands' => ['SwitchboardApiController', 'commands'],
            'switchboard/providers' => ['SwitchboardApiController', 'providers'],
            'switchboard/search' => ['SwitchboardApiController', 'search'],
            'switchboard/run' => ['SwitchboardApiController', 'run'],
            'switchboard/used' => ['SwitchboardApiController', 'used'],
            'switchboard/settings' => ['SwitchboardSettingsController', 'show'],
            'switchboard/settings/save' => ['SwitchboardSettingsController', 'save'],
            'switchboard/admin' => ['SwitchboardSettingsController', 'admin'],
            'switchboard/admin/save' => ['SwitchboardSettingsController', 'adminSave'],
        ];
        foreach ($routes as $path => [$controller, $action]) {
            $this->route->addRoute($path, $controller, $action, 'Switchboard');
        }
    }
}
