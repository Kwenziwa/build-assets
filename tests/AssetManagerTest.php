<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Tests;

use InfluxIT\BuildAssets\Core\AssetManager;
use InfluxIT\BuildAssets\Core\Configuration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssetManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/build-assets-test-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/public/build/assets', 0775, true);
        file_put_contents($this->root . '/public/build/manifest.json', '{"test":true}');
        file_put_contents($this->root . '/public/build/assets/app.js', 'console.log("hello")');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) { return; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($this->root);
    }

    public function testPackVerifyExtractSkipAndForce(): void
    {
        $assets = new AssetManager(Configuration::load($this->root));
        $manifest = $assets->pack();
        self::assertSame(2, $manifest['files']);
        self::assertSame('extracted', $assets->extract());
        self::assertSame('unchanged', $assets->extract());
        self::assertSame('extracted', $assets->extract(true));
        self::assertFileExists($this->root . '/public/build/assets/app.js');
        self::assertTrue($assets->status()['deployed']);
    }

    public function testTamperedArchiveFailsVerification(): void
    {
        $assets = new AssetManager(Configuration::load($this->root));
        $assets->pack();
        file_put_contents($this->root . '/build.zip', 'tamper', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum mismatch');
        $assets->extract();
    }

    public function testSymlinkSourceIsRejected(): void
    {
        if (!function_exists('symlink')) { self::markTestSkipped('Symlinks unavailable'); }
        symlink('/tmp', $this->root . '/public/build/linked');
        $assets = new AssetManager(Configuration::load($this->root));
        $this->expectException(RuntimeException::class);
        $assets->pack();
    }

    public function testPackWorksAgainAfterExtract(): void
    {
        $assets = new AssetManager(Configuration::load($this->root));
        $first = $assets->pack();
        $assets->extract();
        self::assertFileExists($this->root . '/public/build/.build-assets-state.json');
        $second = $assets->pack();
        self::assertSame(2, $second['files']);
        self::assertSame($first['sha256'], $second['sha256']);
    }

    public function testArchiveIsReproducible(): void
    {
        $assets = new AssetManager(Configuration::load($this->root));
        $first = $assets->pack()['sha256'];
        touch($this->root . '/public/build/assets/app.js', time() - 86400);
        chmod($this->root . '/public/build/assets/app.js', 0600);
        $zone = getenv('TZ');
        putenv('TZ=Asia/Tokyo');
        try { $second = $assets->pack()['sha256']; }
        finally { putenv($zone === false ? 'TZ' : 'TZ=' . $zone); }
        self::assertSame($first, $second);
        self::assertSame('0644', substr(sprintf('%o', fileperms($this->root . '/build.zip')), -4));
    }

    public function testMissingCustomConfigIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Config file not found');
        Configuration::load($this->root, $this->root . '/does-not-exist.php');
    }

    public function testSymlinkedRootIsResolved(): void
    {
        if (!function_exists('symlink')) { self::markTestSkipped('Symlinks unavailable'); }
        $link = $this->root . '-current';
        symlink($this->root, $link);
        try {
            $defaults = require dirname(__DIR__) . '/config/build-assets.php';
            $config = new Configuration($link, $defaults);
            self::assertSame(realpath($this->root), $config->root);
            self::assertSame(2, (new AssetManager($config))->pack()['files']);
        } finally { unlink($link); }
    }

    public function testTraversalConfigurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration($this->root, ['source' => '../etc', 'destination' => 'public/build', 'archive' => 'build.zip', 'manifest' => 'build-manifest.json']);
    }
}
