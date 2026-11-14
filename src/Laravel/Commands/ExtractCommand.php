<?php

declare(strict_types=1);
namespace InfluxIT\BuildAssets\Laravel\Commands;
use Illuminate\Console\Command;
use InfluxIT\BuildAssets\Core\AssetManager;
use Throwable;
final class ExtractCommand extends Command
{
    protected $signature = 'build:extract {--force : Extract even when already current}';
    protected $description = 'Verify and safely deploy frontend assets from ZIP';
    public function handle(AssetManager $assets): int
    {
        try { $this->info('Assets ' . $assets->extract((bool) $this->option('force')) . '.'); return self::SUCCESS; }
        catch (Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
