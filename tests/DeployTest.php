<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Tests;

use InfluxIT\BuildAssets\Core\AssetManager;
use InfluxIT\BuildAssets\Core\Configuration;
use InfluxIT\BuildAssets\Git\GitManager;
use PHPUnit\Framework\TestCase;

final class DeployTest extends TestCase
{
    private string $base;
    private string $dev;
    private string $server;

    protected function setUp(): void
    {
        if (!function_exists('exec') || !function_exists('proc_open')) { self::markTestSkipped('Process invocation unavailable.'); }
        $this->base = (string) realpath(sys_get_temp_dir()) . '/build-assets-deploy-' . bin2hex(random_bytes(5));
        mkdir($this->base);
        $git = new GitManager($this->base);
        $git->run(['init', '--bare', '-b', 'main', 'origin.git']);
        $git->run(['clone', 'origin.git', 'dev']);
        $this->dev = $this->base . '/dev';
        $this->server = $this->base . '/server';
        mkdir($this->dev . '/public/build/assets', 0775, true);
        file_put_contents($this->dev . '/public/build/assets/app.js', 'console.log(1)');
        // The lock file is deliberately not ignored: it must not block a pull.
        file_put_contents($this->dev . '/.gitignore', "/public/\n");
        (new AssetManager(Configuration::load($this->dev)))->pack();
        $this->commitAndPush('first build');
        $git->run(['clone', 'origin.git', 'server']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->base) || !is_dir($this->base)) { return; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
            else { chmod($entry->getPathname(), 0644); unlink($entry->getPathname()); }
        }
        rmdir($this->base);
    }

    public function testPullIsNotBlockedByUntrackedLockFile(): void
    {
        $assets = new AssetManager(Configuration::load($this->server));
        self::assertSame('extracted', $assets->extract());
        self::assertFileExists($this->server . '/.build-assets.lock');
        self::assertSame('unchanged', $assets->deploy(true));
    }

    public function testPullStillRefusesModifiedTrackedFiles(): void
    {
        file_put_contents($this->server . '/.gitignore', "changed\n");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusing to pull');
        (new AssetManager(Configuration::load($this->server)))->deploy(true);
    }

    public function testDeployPullUsesPulledConfiguration(): void
    {
        [$exit, $output] = $this->cli(['deploy', '--pull']);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Assets extracted.', $output);

        // A later commit moves the destination. The process that pulls loaded the old
        // configuration, so only a fresh process can extract this correctly.
        file_put_contents($this->dev . '/build-assets.php', "<?php return ['destination' => 'public/dist'];\n");
        file_put_contents($this->dev . '/public/build/assets/app.js', 'console.log(2)');
        (new AssetManager(Configuration::load($this->dev)))->pack();
        $this->commitAndPush('second build');

        [$exit, $output] = $this->cli(['deploy', '--pull']);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Assets extracted.', $output);
        self::assertSame('console.log(2)', file_get_contents($this->server . '/public/dist/assets/app.js'));

        [$exit, $output] = $this->cli(['deploy', '--pull']);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Assets unchanged.', $output);
    }

    private function commitAndPush(string $message): void
    {
        $git = new GitManager($this->dev);
        $git->run(['add', '-A']);
        $git->run(['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-m', $message]);
        $git->run(['push', 'origin', 'HEAD:main']);
    }

    /** @return array{int, string} */
    private function cli(array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/build-assets')
            . ' ' . escapeshellarg('--root=' . $this->server);
        foreach ($args as $arg) { $command .= ' ' . escapeshellarg($arg); }
        exec($command . ' 2>&1', $lines, $exit);
        return [$exit, implode("\n", $lines)];
    }
}
