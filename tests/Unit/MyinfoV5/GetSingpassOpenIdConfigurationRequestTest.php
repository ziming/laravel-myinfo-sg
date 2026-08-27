<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GetSingpassOpenIdConfigurationRequestTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        config()->set('laravel-myinfo-sg-v5.issuer_uri', 'https://stg-id.singpass.gov.sg');
        config()->set('laravel-myinfo-sg-v5.safe_read_retry_delay_milliseconds', 0);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    public function test_resolves_the_fapi_discovery_endpoint_and_uses_a_one_hour_cache(): void
    {
        $request = new GetSingpassOpenIdConfigurationRequest;

        $this->assertSame(
            'https://stg-id.singpass.gov.sg/fapi/.well-known/openid-configuration',
            $request->resolveEndpoint(),
        );
        $this->assertSame(3600, $request->cacheExpiryInSeconds());
    }

    public function test_retries_a_retryable_response_and_returns_the_next_success(): void
    {
        $attempts = 0;
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => function () use (&$attempts): MockResponse {
                $attempts++;

                return $attempts === 1
                    ? MockResponse::make([], 503)
                    : MockResponse::make(['issuer' => 'https://stg-id.singpass.gov.sg/fapi']);
            },
        ]);

        $response = (new GetSingpassOpenIdConfigurationRequest)->send();

        $this->assertTrue($response->successful());
        $this->assertSame(2, $attempts);
        $mockClient->assertSentCount(2, GetSingpassOpenIdConfigurationRequest::class);
    }

    public function test_does_not_retry_a_non_retryable_client_error(): void
    {
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make([
                'error' => 'invalid_request',
            ], 400),
        ]);

        $response = (new GetSingpassOpenIdConfigurationRequest)->send();

        $this->assertSame(400, $response->status());
        $mockClient->assertSentCount(1, GetSingpassOpenIdConfigurationRequest::class);
    }
}
