<?php

use Kanboard\Core\Plugin\Hook;
use Kanboard\Plugin\Switchboard\Plugin;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

final class AssetsTest extends TestCase
{
    public function testStylesheetIsRegistered(): void
    {
        $container = new Container(['hook' => new Hook()]);
        (new Plugin($container))->initialize();

        $this->assertContains(
            ['template' => 'plugins/Switchboard/Assets/css/switchboard.css'],
            $container['hook']->getListeners('template:layout:css')
        );
    }

    public function testStylesheetUsesFrozenTokenFallbacks(): void
    {
        $css = $this->stylesheet();
        foreach ([
            'hsl(var(--popover, 0 0% 100%))',
            'hsl(var(--foreground, 222 47% 11%))',
            'hsl(var(--muted-foreground, 215 16% 47%))',
            'hsl(var(--border, 214 32% 91%))',
            'hsl(var(--ring, 222 84% 5%))',
            'hsl(var(--highlight, var(--accent, 210 40% 96%)))',
        ] as $color) {
            $this->assertStringContainsString($color, $css);
        }
    }

    public function testMotionRequiresNoReducedMotionPreference(): void
    {
        foreach ($this->declarations() as [$property, $value, $allowsMotion]) {
            if (preg_match('/^(?:-\w+-)?(?:transition|animation)(?:-|$)/i', $property)) {
                $this->assertTrue($allowsMotion, "$property: $value is outside the motion media block");
            }
        }
    }

    public function testHexColorsAreLimitedToTokenFallbacks(): void
    {
        $hexColor = '\#(?:[a-f0-9]{3,4}|[a-f0-9]{6}|[a-f0-9]{8})(?![\w-])';
        foreach ($this->declarations() as [$property, $value]) {
            $outsideFallbacks = preg_replace('/var\(\s*--[\w-]+\s*,\s*'.$hexColor.'\s*\)/i', '', $value);
            $this->assertDoesNotMatchRegularExpression('/'.$hexColor.'/i', $outsideFallbacks, "$property: $value");
        }
    }

    public function testTemplatesHaveNoScriptOrInlineHandlers(): void
    {
        $directory = __DIR__.'/../Template';
        $this->assertDirectoryExists($directory);
        $templates = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $templates[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($templates);
        foreach ($templates as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsStringIgnoringCase('<script', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $source, $path);
        }
    }

    private function stylesheet(): string
    {
        $path = __DIR__.'/../Assets/css/switchboard.css';
        $this->assertFileExists($path);

        return preg_replace('~/\*.*?\*/~s', '', file_get_contents($path));
    }

    private function declarations(): iterable
    {
        // Strip strings and URL values before scanning stylesheet delimiters.
        $css = preg_replace('~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|url\([^)]*\)~is', '', $this->stylesheet());
        preg_match_all('/([^{};]*)([{};])/', $css, $tokens, PREG_SET_ORDER);
        $motionBlocks = [false];
        foreach ($tokens as [, $statement, $delimiter]) {
            if ($delimiter === '{') {
                $motionBlocks[] = end($motionBlocks)
                    || preg_match('/^\s*@media\s+\(\s*prefers-reduced-motion\s*:\s*no-preference\s*\)\s*$/i', $statement);
                continue;
            }
            if (preg_match('/^\s*([\w-]+)\s*:\s*(.*)\s*$/s', $statement, $declaration)) {
                yield [$declaration[1], $declaration[2], end($motionBlocks)];
            }
            if ($delimiter === '}') {
                array_pop($motionBlocks);
            }
        }
    }
}
