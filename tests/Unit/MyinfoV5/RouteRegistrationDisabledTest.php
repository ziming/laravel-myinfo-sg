<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Illuminate\Support\Facades\Route;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class RouteRegistrationDisabledTest extends TestCase
{
    public function test_both_v5_routes_are_off_by_default(): void
    {
        $this->assertFalse(config('laravel-myinfo-sg-v5.enable_default_myinfo_authorization_redirect_route'));
        $this->assertFalse(config('laravel-myinfo-sg-v5.enable_default_public_jwks_endpoint_route'));

        $this->assertNull(Route::getRoutes()->getByName('myinfo-v5.singpass'));
        $this->assertNull(Route::getRoutes()->getByName('myinfo-v5.public-jwks'));
    }
}
