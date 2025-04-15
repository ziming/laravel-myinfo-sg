<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;


if (config('laravel-myinfo-sg.enable_default_myinfo_routes')) {
    Route::post(
        config('laravel-myinfo-sg.call_authorise_api_url'),
        config('laravel-myinfo-sg.call_authorise_api_controller')
    )->name('myinfo.singpass')->middleware('web');

    Route::post(
        config('laravel-myinfo-sg.get_myinfo_person_data_url'),
        config('laravel-myinfo-sg.get_myinfo_person_data_controller')
    )->name('myinfo.person');
}


if (config('laravel-myinfo-sg-v5.enable_default_myinfo_authorization_redirect_route')) {
    Route::post(
        config('laravel-myinfo-sg-v5.call_authorization_api_uri'),
        config('laravel-myinfo-sg-v5.call_authorization_api_controller')
    )
        ->name('myinfo-v5.singpass')
        ->middleware('web');
}

if (config('laravel-myinfo-sg-v5.enable_default_public_jwks_endpoint_route')) {
    Route::get(
        config('laravel-myinfo-sg-v5.public_jwks_uri'),
        config('laravel-myinfo-sg-v5.public_jwks_controller')
    )
        ->name('myinfo-v5.public-jwks');
}
