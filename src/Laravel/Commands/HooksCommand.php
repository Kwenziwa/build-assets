<?php

declare(strict_types=1);
namespace InfluxIT\BuildAssets\Laravel\Commands;
use Illuminate\Console\Command;
use InfluxIT\BuildAssets\Core\AssetManager;
use InfluxIT\BuildAssets\Git\GitManager;
use InfluxIT\BuildAssets\Git\HookInstaller;
use Throwable;
final class HooksCommand extends Command
{
    protected $signature = 'build:hooks {action : install or remove}';
    protected $description = 'Install or remove managed Git extraction hooks';
    public function handle(AssetManager $assets): int
    {
        try {
            $hooks = new HookInstaller(new GitManager($assets->config->root));
            if ($this->argument('action') === 'install') { $hooks->install(); $this->info('Git hooks installed.'); }
            elseif ($this->argument('action') === 'remove') { $hooks->remove(); $this->info('Git hooks removed.'); }
            else { $this->error('Action must be install or remove.'); return self::FAILURE; }
            return self::SUCCESS;
        } catch (Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
