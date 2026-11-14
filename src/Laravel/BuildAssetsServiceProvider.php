<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Laravel;

use Illuminate\Support\ServiceProvider;
use InfluxIT\BuildAssets\Core\AssetManager;
use InfluxIT\BuildAssets\Core\Configuration;
use InfluxIT\BuildAssets\Laravel\Commands\DeployCommand;
use InfluxIT\BuildAssets\Laravel\Commands\ExtractCommand;
use InfluxIT\BuildAssets\Laravel\Commands\HooksCommand;
use InfluxIT\BuildAssets\Laravel\Commands\PackCommand;
use InfluxIT\BuildAssets\Laravel\Commands\StatusCommand;

final class BuildAssetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/build-assets.php', 'build-assets');
        $this->app->singleton(AssetManager::class, function ($app) {
            return new AssetManager(new Configuration(base_path(), $app['config']->get('build-assets')));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([dirname(__DIR__, 2) . '/config/build-assets.php' => config_path('build-assets.php')], 'build-assets-config');
            $this->commands([PackCommand::class, ExtractCommand::class, StatusCommand::class, HooksCommand::class, DeployCommand::class]);
        }
    }
}
