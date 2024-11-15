<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::post(
    config('laravel-myinfo-sg.call_authorise_api_url'),
    config('laravel-myinfo-sg.call_authorise_api_controller')
)->name('myinfo.singpass')->middleware('web');

Route::post(
    config('laravel-myinfo-sg.get_myinfo_person_data_url'),
    config('laravel-myinfo-sg.get_myinfo_person_data_controller')
)->name('myinfo.person');
