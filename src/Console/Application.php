<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Console;

use InfluxIT\BuildAssets\Core\AssetManager;
use InfluxIT\BuildAssets\Core\Configuration;
use InfluxIT\BuildAssets\Git\GitManager;
use InfluxIT\BuildAssets\Git\HookInstaller;
use Throwable;

final class Application
{
    public function run(array $args): int
    {
        try {
            $script = (string) array_shift($args);
            if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
                $this->help();
                return 0;
            }
            $root = getcwd() ?: '.';
            $configFile = null;
            foreach ($args as $index => $arg) {
                if (str_starts_with($arg, '--root=')) { $root = substr($arg, 7); unset($args[$index]); }
                elseif (str_starts_with($arg, '--config=')) { $configFile = substr($arg, 9); unset($args[$index]); }
            }
            $args = array_values($args);
            $command = array_shift($args);
            $config = Configuration::load($root, $configFile);
            $assets = new AssetManager($config);
            switch ($command) {
                case 'pack':
                    $this->assertOptions($args, ['--compile']);
                    $manifest = $assets->pack(in_array('--compile', $args, true));
                    echo "Archive created ({$manifest['files']} files, SHA-256 {$manifest['sha256']}).\n";
                    break;
                case 'extract':
                    $this->assertOptions($args, ['--force']);
                    echo 'Assets ' . $assets->extract(in_array('--force', $args, true)) . ".\n";
                    break;
                case 'status':
                    $this->assertOptions($args, []);
                    echo json_encode($assets->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    break;
                case 'deploy':
                    $this->assertOptions($args, ['--pull']);
                    $extract = [PHP_BINARY !== '' ? PHP_BINARY : 'php', realpath($script) ?: $script, '--root=' . $config->root];
                    if ($configFile !== null) { $extract[] = '--config=' . (realpath($configFile) ?: $configFile); }
                    $extract[] = 'extract';
                    echo 'Assets ' . $assets->deploy(in_array('--pull', $args, true), $extract) . ".\n";
                    break;
                case 'hooks':
                    $action = array_shift($args);
                    $this->assertOptions($args, []);
                    $hooks = new HookInstaller(new GitManager($config->root));
                    if ($action === 'install') { $hooks->install(); echo "Git hooks installed.\n"; }
                    elseif ($action === 'remove') { $hooks->remove(); echo "Git hooks removed.\n"; }
                    else { throw new \InvalidArgumentException('Use hooks install or hooks remove.'); }
                    break;
                default:
                    throw new \InvalidArgumentException("Unknown command: $command");
            }
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, "Build assets error: {$error->getMessage()}\n");
            return 1;
        }
    }

    private function assertOptions(array $args, array $allowed): void
    {
        foreach ($args as $arg) {
            if (!in_array($arg, $allowed, true)) {
                throw new \InvalidArgumentException("Unexpected argument: $arg");
            }
        }
    }

    private function help(): void
    {
        echo <<<'HELP'
Build Assets CLI

Commands:
  pack [--compile]      ZIP compiled assets (optionally run configured frontend build)
  extract [--force]     Verify and extract assets; skip if already current
  status                Check archive integrity and current deployment
  hooks install         Add post-merge and post-rewrite extraction hooks
  hooks remove          Remove managed hook sections
  deploy [--pull]       Extract, optionally first run git pull --ff-only

Global options:
  --root=/path          Application root (default: current directory)
  --config=/path        Custom PHP config file (default: build-assets.php)
  --help                Print this help

HELP;
        echo "\n";
    }
}
