<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use ReflectionMethod;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses\GetUserResponse;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GetUserResponseTest extends TestCase
{
    public function test_expected_issuer_uses_the_discovered_issuer(): void
    {
        $method = new ReflectionMethod(GetUserResponse::class, 'resolveExpectedIssuer');
        $method->setAccessible(true);

        $issuer = $method->invoke(null, [
            'issuer' => 'https://stg-id.singpass.gov.sg/fapi',
        ]);

        $this->assertSame('https://stg-id.singpass.gov.sg/fapi', $issuer);
    }

    public function test_expected_issuer_falls_back_to_the_fapi_issuer(): void
    {
        config()->set('laravel-myinfo-sg-v6.issuer_uri', 'https://stg-id.singpass.gov.sg');

        $method = new ReflectionMethod(GetUserResponse::class, 'resolveExpectedIssuer');
        $method->setAccessible(true);

        $issuer = $method->invoke(null, []);

        $this->assertSame('https://stg-id.singpass.gov.sg/fapi', $issuer);
    }
}
