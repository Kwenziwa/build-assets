<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Tests;

use InfluxIT\BuildAssets\Git\GitManager;
use InfluxIT\BuildAssets\Git\HookInstaller;
use PHPUnit\Framework\TestCase;

final class HookInstallerTest extends TestCase
{
    public function testInstallIdempotentAndRemove(): void
    {
        if (!function_exists('exec')) { self::markTestSkipped('Git process invocation unavailable.'); }
        $root = sys_get_temp_dir() . '/build-assets-hook-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            $git = new GitManager($root);
            $git->run(['init']);
            $hooks = new HookInstaller($git);
            $hooks->install();
            $hooks->install();
            $path = $git->hooksDirectory() . '/post-merge';
            self::assertSame(1, substr_count((string) file_get_contents($path), '# >>> influxit/build-assets >>>'));
            $hooks->remove();
            self::assertFileDoesNotExist($path);
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
            rmdir($root);
        }
    }
}
