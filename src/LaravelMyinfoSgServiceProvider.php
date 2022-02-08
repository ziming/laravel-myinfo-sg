<?php

namespace Ziming\LaravelMyinfoSg;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelMyinfoSgServiceProvider extends PackageServiceProvider
{
    public function bootingPackage()
    {
        parent::bootingPackage();
        if ($this->app->runningInConsole()) {

            $this->publishes([
                __DIR__.'/../myinfo-ssl/staging_myinfo_public_cert.cer'         => storage_path('myinfo-ssl/staging_myinfo_public_cert.cer'),
                __DIR__.'/../myinfo-ssl/stg-demoapp-client-privatekey-2018.pem' => storage_path('myinfo-ssl/stg-demoapp-client-privatekey-2018.pem'),
                __DIR__.'/../myinfo-ssl/stg-demoapp-client-publiccert-2018.pem' => storage_path('myinfo-ssl/stg-demoapp-client-publiccert-2018.pem'),
            ], 'myinfo-ssl');
        }
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-myinfo-sg')
            ->hasConfigFile('laravel-myinfo-sg');

        if (! config('laravel-myinfo-sg.enable_default_myinfo_routes')) {
            return;
        };

        $package->hasRoute('web');
    }

    public function packageRegistered(): void
    {
        $this->app->bind('laravel-myinfo-sg', LaravelMyinfoSg::class);
    }
}
