<?php

namespace Kanboard\Plugin\Switchboard;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
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
        $this->container['switchboardRegistry'] = $this->container->factory(
            static fn(Container $c): Registry => new Registry($c)
        );
        $this->container['switchboardProviders'] = $this->container->factory(
            static fn(Container $c): Providers => new Providers($c)
        );
        $this->container['switchboardFrecency'] = $this->container->factory(
            static fn(Container $c): Frecency => new Frecency($c)
        );

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
}
