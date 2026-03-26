<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Illuminate\Contracts\Routing\ResponseFactory;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\PublicJwksController;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class PublicJwksControllerTest extends TestCase
{
    public function test_it_wraps_a_raw_key_list_in_a_jwks_keys_payload(): void
    {
        config()->set('laravel-myinfo-sg-v6.public_jwks', json_encode([
            ['kty' => 'EC', 'kid' => 'sig-1'],
        ], JSON_THROW_ON_ERROR));

        $response = (new PublicJwksController)(
            $this->app->make(ResponseFactory::class)
        );

        $this->assertSame(
            ['keys' => [['kty' => 'EC', 'kid' => 'sig-1']]],
            $response->getData(true)
        );
    }

    public function test_it_returns_an_existing_jwks_payload_as_is(): void
    {
        config()->set('laravel-myinfo-sg-v6.public_jwks', json_encode([
            'keys' => [['kty' => 'EC', 'kid' => 'sig-1']],
        ], JSON_THROW_ON_ERROR));

        $response = (new PublicJwksController)(
            $this->app->make(ResponseFactory::class)
        );

        $this->assertSame(
            ['keys' => [['kty' => 'EC', 'kid' => 'sig-1']]],
            $response->getData(true)
        );
    }
}
