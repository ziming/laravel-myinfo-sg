<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Ziming\LaravelMyinfoSg\Console\Commands\GenerateJwkSetCommand;

class LaravelMyinfoSgServiceProvider extends PackageServiceProvider
{
    public function bootingPackage(): void
    {
        parent::bootingPackage();
        if ($this->app->runningInConsole()) {

            $this->publishes([
                __DIR__.'/../myinfo-ssl/staging_myinfo_public_cert.cer'         => storage_path('myinfo-ssl/staging_myinfo_public_cert.cer'),
                __DIR__.'/../myinfo-ssl/stg-demoapp-client-privatekey-2018.pem' => storage_path('myinfo-ssl/stg-demoapp-client-privatekey-2018.pem'),
            ], 'myinfo-ssl');
        }
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-myinfo-sg')
            ->hasCommands([GenerateJwkSetCommand::class])
            ->hasConfigFile('laravel-myinfo-sg');

        $package->hasRoute('web');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton('laravel-myinfo-sg', LaravelMyinfoSg::class);
    }
}
