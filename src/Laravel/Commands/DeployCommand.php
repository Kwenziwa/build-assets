<?php

declare(strict_types=1);
namespace InfluxIT\BuildAssets\Laravel\Commands;
use Illuminate\Console\Command;
use InfluxIT\BuildAssets\Core\AssetManager;
use Throwable;
final class DeployCommand extends Command
{
    protected $signature = 'build:deploy {--pull : Run git pull --ff-only before extraction}';
    protected $description = 'Deploy compiled assets, optionally pulling Git first';
    public function handle(AssetManager $assets): int
    {
        try {
            $extract = [PHP_BINARY !== '' ? PHP_BINARY : 'php', base_path('artisan'), 'build:extract'];
            $this->info('Assets ' . $assets->deploy((bool) $this->option('pull'), $extract) . '.');
            return self::SUCCESS;
        }
        catch (Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
