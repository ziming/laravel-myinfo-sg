<?php

namespace Ziming\LaravelMyinfoSg;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ziming\LaravelMyinfoSg\Http\Controllers\CallAuthoriseApiController;
use Ziming\LaravelMyinfoSg\Http\Controllers\GetMyinfoPersonDataController;

class LaravelMyinfoSgServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/config.php' => config_path('laravel-myinfo-sg.php'),
            ], 'config');

            $this->publishes([
                __DIR__.'/../myinfo-ssl/stg-auth-signing-public.pem' => storage_path('myinfo-ssl/stg-auth-signing-public.pem'),
                __DIR__.'/../myinfo-ssl/stg-demoapp-client-privatekey-2018.pem' => storage_path('myinfo-ssl/stg-demoapp-client-privatekey-2018.pem'),
                __DIR__.'/../myinfo-ssl/stg-demoapp-client-publiccert-2018.pem' => storage_path('myinfo-ssl/stg-demoapp-client-publiccert-2018.pem'),
            ], 'myinfo-ssl');
        }

        if (! config('laravel-myinfo-sg.enable_default_myinfo_routes')) {
            return;
        }

        Route::post(config('laravel-myinfo-sg.call_authorise_api_uri'), CallAuthoriseApiController::class)->name('myinfo.singpass');
        Route::post(config('laravel-myinfo-sg.get_myinfo_person_data_uri'), GetMyinfoPersonDataController::class)->name('myinfo.person');
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'laravel-myinfo-sg');

        // Register the main class to use with the facade
        $this->app->singleton('laravel-myinfo-sg', function () {
            return new LaravelMyinfoSg;
        });
    }
}
