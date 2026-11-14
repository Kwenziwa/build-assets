<?php

declare(strict_types=1);
namespace InfluxIT\BuildAssets\Laravel\Commands;
use Illuminate\Console\Command;
use InfluxIT\BuildAssets\Core\AssetManager;
use Throwable;
final class StatusCommand extends Command
{
    protected $signature = 'build:status';
    protected $description = 'Inspect ZIP integrity and deployed assets';
    public function handle(AssetManager $assets): int
    {
        try { $this->line(json_encode($assets->status(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); return self::SUCCESS; }
        catch (Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
