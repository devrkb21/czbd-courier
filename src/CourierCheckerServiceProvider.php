<?php

namespace Czbd\CourierChecker;

use Illuminate\Support\ServiceProvider;
use Czbd\CourierChecker\Services\SteadfastService;
use Czbd\CourierChecker\Services\PathaoService;
use Czbd\CourierChecker\Services\RedxService;
use Czbd\CourierChecker\CourierCheckerManager;

use Czbd\CourierChecker\Services\PaperflyService;
use Czbd\CourierChecker\Services\CarrybeeService;

/**
 * Class CourierCheckerServiceProvider
 *
 * Registers the package services and merges configurations into the Laravel container.
 *
 * @package Czbd\CourierChecker
 */
class CourierCheckerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish the config file on vendor:publish
        $this->publishes([
            __DIR__ . '/../config/courier-checker.php' => config_path('courier-checker.php'),
        ], 'courier-checker-config');

        // Keep Laravel's default config tag support for convenience
        $this->publishes([
            __DIR__ . '/../config/courier-checker.php' => config_path('courier-checker.php'),
        ], 'config');
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/courier-checker.php',
            'courier-checker'
        );

        $this->app->singleton('courier-checker', function ($app) {
            return new CourierCheckerManager(
                $app->make(SteadfastService::class),
                $app->make(PathaoService::class),
                $app->make(RedxService::class),
                $app->make(PaperflyService::class),
                $app->make(CarrybeeService::class)
            );
        });
    }
}
