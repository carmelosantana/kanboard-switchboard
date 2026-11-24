<?php

use Kanboard\Plugin\Switchboard\Plugin;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

final class PluginTest extends TestCase
{
    public function testVersionsAgree(): void
    {
        $manifest = $this->readManifest();
        $plugin = new Plugin(new Container());

        $this->assertSame('1.0.1', $manifest['version']);
        $this->assertSame($manifest['version'], $plugin->getPluginVersion());
    }

    public function testManifest(): void
    {
        $manifest = $this->readManifest();

        $this->assertSame('>=1.2.47', $manifest['kanboard_version']);
        $this->assertSame('>=8.4', $manifest['php_version']);
        $this->assertSame([], $manifest['requires']);
        $this->assertArrayHasKey('recommends', $manifest);
        $this->assertIsArray($manifest['recommends']);
        $this->assertTrue(array_is_list($manifest['recommends']));
        $this->assertCount(1, $manifest['recommends']);
        $recommendation = $manifest['recommends'][0];
        $this->assertSame('Harbor', $recommendation['plugin']);
        $this->assertSame("uses Harbor's tokens and dialog styling when installed", $recommendation['reason']);
    }

    private function readManifest(): array
    {
        $path = __DIR__.'/../plugin.json';
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
