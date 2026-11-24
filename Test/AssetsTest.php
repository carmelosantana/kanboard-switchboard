<?php

use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase
{
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
}
