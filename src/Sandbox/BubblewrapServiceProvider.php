<?php

namespace SecureRun\Sandbox;

use SecureRun\BubblewrapSandboxRunner;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider that binds the BubblewrapSandbox into the container.
 */
class BubblewrapServiceProvider extends ServiceProvider
{
    /**
     * Register bindings.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/sandbox.php', 'sandbox');

        $this->app->singleton(BubblewrapSandboxRunner::class, function ($app) {
            $config = $app['config']->get('sandbox', array());

            return BubblewrapSandboxRunner::fromConfig($config);
        });

        $this->app->alias(BubblewrapSandboxRunner::class, 'sandbox.bwrap');
    }

    /**
     * Publish config for Laravel apps.
     *
     * @return void
     */
    public function boot()
    {
        if (function_exists('config_path')) {
            $this->publishes(array(
                __DIR__ . '/../../config/sandbox.php' => config_path('sandbox.php'),
            ), 'sandbox-config');
        }
    }
}
