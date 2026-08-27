<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Illuminate\Support\Facades\Route;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV5\CallAuthorizationApiController;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV5\PublicJwksController;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class RouteRegistrationTest extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        config()->set('laravel-myinfo-sg-v5.enable_default_myinfo_authorization_redirect_route', true);
        config()->set('laravel-myinfo-sg-v5.enable_default_public_jwks_endpoint_route', true);
    }

    public function test_it_registers_the_authorization_redirect_route_when_enabled(): void
    {
        $route = Route::getRoutes()->getByName('myinfo-v5.singpass');

        $this->assertNotNull($route);
        $this->assertSame('redirect-to-singpass-v5', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertSame(CallAuthorizationApiController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_it_registers_the_public_jwks_route_when_enabled(): void
    {
        $route = Route::getRoutes()->getByName('myinfo-v5.public-jwks');

        $this->assertNotNull($route);
        $this->assertSame('sp/v5/jwks', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertSame(PublicJwksController::class, $route->getActionName());
    }
}
