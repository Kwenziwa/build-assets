<?php

declare(strict_types=1);
namespace InfluxIT\BuildAssets\Laravel\Commands;
use Illuminate\Console\Command;
use InfluxIT\BuildAssets\Core\AssetManager;
use Throwable;
final class PackCommand extends Command
{
    protected $signature = 'build:assets {--compile : Run the configured frontend build first}';
    protected $description = 'Create a ZIP of compiled frontend assets';
    public function handle(AssetManager $assets): int
    {
        try {
            $result = $assets->pack((bool) $this->option('compile'));
            $this->info("Packaged {$result['files']} assets. SHA-256: {$result['sha256']}");
            return self::SUCCESS;
        } catch (Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
