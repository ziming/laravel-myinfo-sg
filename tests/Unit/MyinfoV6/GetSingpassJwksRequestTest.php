<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassJwksRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GetSingpassJwksRequestTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        config()->set('laravel-myinfo-sg-v6.safe_read_retry_delay_milliseconds', 0);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

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

    public function test_retries_a_retryable_response_and_returns_the_next_success(): void
    {
        $attempts = 0;
        $mockClient = MockClient::global([
            GetSingpassJwksRequest::class => function () use (&$attempts): MockResponse {
                $attempts++;

                return $attempts === 1
                    ? MockResponse::make([], 429)
                    : MockResponse::make(['keys' => [['kid' => 'current-key']]]);
            },
        ]);

        $response = (new GetSingpassJwksRequest(
            'https://stg-id.singpass.gov.sg/fapi/.well-known/jwks.json',
        ))->send();

        $this->assertTrue($response->successful());
        $this->assertSame(2, $attempts);
        $mockClient->assertSentCount(2, GetSingpassJwksRequest::class);
    }

    public function test_does_not_retry_a_non_retryable_authentication_error(): void
    {
        $mockClient = MockClient::global([
            GetSingpassJwksRequest::class => MockResponse::make([], 401),
        ]);

        $response = (new GetSingpassJwksRequest(
            'https://stg-id.singpass.gov.sg/fapi/.well-known/jwks.json',
        ))->send();

        $this->assertSame(401, $response->status());
        $mockClient->assertSentCount(1, GetSingpassJwksRequest::class);
    }
}
