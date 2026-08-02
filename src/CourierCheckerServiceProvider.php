<?php

namespace Czbd\CourierChecker;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Czbd\CourierChecker\Contracts\CourierServiceInterface;
use Czbd\CourierChecker\Services\SteadfastService;
use Czbd\CourierChecker\Services\PathaoService;
use Czbd\CourierChecker\Services\RedxService;
use Czbd\CourierChecker\Services\UnavailableCourierService;
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
                $this->resolveCourierService($app, 'steadfast', SteadfastService::class),
                $this->resolveCourierService($app, 'pathao', PathaoService::class),
                $this->resolveCourierService($app, 'redx', RedxService::class),
                $this->resolveCourierService($app, 'paperfly', PaperflyService::class),
                $this->resolveCourierService($app, 'carrybee', CarrybeeService::class)
            );
        });
    }

    /**
     * Resolve a courier service, falling back to a stand-in when its
     * credentials are missing or invalid so one misconfigured courier
     * cannot prevent the others from working.
     *
     * @param string $courier
     * @param class-string<CourierServiceInterface> $class
     */
    protected function resolveCourierService($app, string $courier, string $class): CourierServiceInterface
    {
        try {
            return $app->make($class);
        } catch (InvalidArgumentException $e) {
            Log::warning("CourierChecker: {$courier} is not configured and will be skipped.", [
                'message' => $e->getMessage(),
            ]);

            return new UnavailableCourierService($courier, $e->getMessage());
        }
    }
}
