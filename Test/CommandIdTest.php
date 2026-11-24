<?php

use Kanboard\Plugin\Switchboard\Model\CommandId;
use PHPUnit\Framework\TestCase;

final class CommandIdTest extends TestCase
{
    public function testAcceptsDottedLowercaseIds(): void
    {
        foreach (['core.go-dashboard', 'switchboard.tasks', 'a1.b_c.d-e'] as $id) {
            $this->assertTrue(CommandId::isValid($id), $id);
        }
    }

    public function testRejectsUndottedUppercaseOrTrailingNewlineIds(): void
    {
        foreach (['', 'core', 'Core.x', 'core.', '.x', "core.x\n", 'core x.y'] as $id) {
            $this->assertFalse(CommandId::isValid($id), var_export($id, true));
        }
    }

    /** Registry, Providers and the settings controller share one rule (#7338). */
    public function testThePatternLivesOnlyInCommandId(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge(glob($root.'/Model/*.php'), glob($root.'/Controller/*.php'), glob($root.'/Provider/*.php'));
        $holders = [];
        foreach ($files as $file) {
            if (str_contains(file_get_contents($file), '[a-z0-9]+(\.[a-z0-9_-]+)+')) {
                $holders[] = basename($file);
            }
        }
        $this->assertSame(['CommandId.php'], $holders);
    }
}
