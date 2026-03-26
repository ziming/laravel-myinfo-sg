<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassJwksRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GetSingpassJwksRequestTest extends TestCase
{
    public function test_resolve_endpoint_returns_the_supplied_jwks_uri(): void
    {
        $request = new GetSingpassJwksRequest('https://stg-id.singpass.gov.sg/fapi/.well-known/jwks.json');

        $this->assertSame(
            'https://stg-id.singpass.gov.sg/fapi/.well-known/jwks.json',
            $request->resolveEndpoint()
        );
    }

    public function test_jwks_request_uses_a_one_hour_cache_ttl(): void
    {
        $request = new GetSingpassJwksRequest('https://stg-id.singpass.gov.sg/fapi/.well-known/jwks.json');

        $this->assertSame(3600, $request->cacheExpiryInSeconds());
    }
}
