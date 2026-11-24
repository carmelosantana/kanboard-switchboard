<?php

namespace Kanboard\Plugin\Switchboard;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Switchboard\Model\Registry;
use Pimple\Container;

class Plugin extends Base
{
    public function initialize(): void
    {
        $this->container['switchboardRegistry'] = $this->container->factory(
            static fn(Container $c): Registry => new Registry($c)
        );
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
