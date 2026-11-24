<?php

namespace Kanboard\Plugin\Switchboard;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
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
