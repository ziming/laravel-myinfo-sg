<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use LogicException;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Connectors\NullConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\MyinfoV6TransportException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoV6Request;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoV6RequestSender;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\PushedAuthorizationRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class MyinfoV6TransportTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('laravel-myinfo-sg-v6.safe_read_retry_delay_milliseconds', 0);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    public function test_default_timeouts_are_applied_to_pending_requests(): void
    {
        $pendingRequest = (new NullConnector)->createPendingRequest(
            new GetSingpassOpenIdConfigurationRequest,
        );

        $this->assertSame(5.0, $pendingRequest->config()->get(RequestOptions::CONNECT_TIMEOUT));
        $this->assertSame(15.0, $pendingRequest->config()->get(RequestOptions::TIMEOUT));
    }

    public function test_configured_timeouts_and_safe_read_retry_settings_are_honored(): void
    {
        config()->set('laravel-myinfo-sg-v6.connect_timeout_seconds', '1.5');
        config()->set('laravel-myinfo-sg-v6.request_timeout_seconds', 9);
        config()->set('laravel-myinfo-sg-v6.safe_read_max_attempts', '3');
        config()->set('laravel-myinfo-sg-v6.safe_read_retry_delay_milliseconds', '0');

        $request = new GetSingpassOpenIdConfigurationRequest;
        $pendingRequest = (new NullConnector)->createPendingRequest($request);

        $this->assertSame(1.5, $pendingRequest->config()->get(RequestOptions::CONNECT_TIMEOUT));
        $this->assertSame(9.0, $pendingRequest->config()->get(RequestOptions::TIMEOUT));
        $this->assertSame(3, $request->tries);
        $this->assertSame(0, $request->retryInterval);
        $this->assertFalse($request->useExponentialBackoff);
        $this->assertFalse($request->throwOnMaxTries);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidTransportConfiguration(): iterable
    {
        foreach ([0, -1, 'nope', INF, NAN, true] as $index => $value) {
            yield "connect timeout {$index}" => ['connect_timeout_seconds', $value];
            yield "request timeout {$index}" => ['request_timeout_seconds', $value];
        }

        foreach ([0, 4, 1.5, '2.5', 'nope'] as $index => $value) {
            yield "attempts {$index}" => ['safe_read_max_attempts', $value];
        }

        foreach ([-1, 5001, 1.5, '1.5', 'nope'] as $index => $value) {
            yield "retry delay {$index}" => ['safe_read_retry_delay_milliseconds', $value];
        }
    }

    #[DataProvider('invalidTransportConfiguration')]
    public function test_invalid_transport_configuration_fails_before_building_a_request(
        string $key,
        mixed $value,
    ): void {
        config()->set("laravel-myinfo-sg-v6.{$key}", $value);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("MyInfo V6 transport configuration [{$key}] is invalid.");

        new GetSingpassOpenIdConfigurationRequest;
    }

    /**
     * @return iterable<string, array{string, bool, bool, int}>
     */
    public static function endpointPolicies(): iterable
    {
        yield 'initial discovery' => ['discovery', false, true, 2];
        yield 'callback discovery' => ['discovery', true, true, 2];
        yield 'PAR' => ['par', true, false, 1];
        yield 'token' => ['token', true, false, 1];
        yield 'callback JWKS' => ['jwks', true, true, 2];
        yield 'UserInfo JWKS' => ['jwks', false, true, 2];
        yield 'UserInfo' => ['userinfo', false, true, 2];
    }

    #[DataProvider('endpointPolicies')]
    public function test_exhausted_retryable_responses_map_to_the_sanitized_transport_boundary(
        string $endpoint,
        bool $restartAuthorization,
        bool $safeRead,
        int $expectedAttempts,
    ): void {
        $mockClient = MockClient::global([
            TestTransportRequest::class => MockResponse::make('provider-body-secret', 503),
        ]);

        try {
            (new MyinfoV6RequestSender)->send(
                new TestTransportRequest($safeRead),
                $endpoint,
                $restartAuthorization,
            );
            $this->fail('Expected the exhausted response to cross the transport boundary.');
        } catch (MyinfoV6TransportException $exception) {
            $this->assertSame('The MyInfo V6 transport request could not be completed.', $exception->getMessage());
            $this->assertSame($endpoint, $exception->endpoint());
            $this->assertSame($restartAuthorization, $exception->restartAuthorization());
            $this->assertStringNotContainsString('provider-body-secret', $exception->getMessage());
        }

        $mockClient->assertSentCount($expectedAttempts, TestTransportRequest::class);
    }

    #[DataProvider('endpointPolicies')]
    public function test_connection_failures_map_to_the_sanitized_transport_boundary(
        string $endpoint,
        bool $restartAuthorization,
        bool $safeRead,
        int $expectedAttempts,
    ): void {
        $attempts = 0;
        MockClient::global([
            TestTransportRequest::class => function () use (&$attempts): MockResponse {
                $attempts++;

                return MockResponse::make()->throw(
                    static fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(
                        new RuntimeException('connection-secret'),
                        $pendingRequest,
                    ),
                );
            },
        ]);

        try {
            (new MyinfoV6RequestSender)->send(
                new TestTransportRequest($safeRead),
                $endpoint,
                $restartAuthorization,
            );
            $this->fail('Expected the fatal request failure to cross the transport boundary.');
        } catch (MyinfoV6TransportException $exception) {
            $this->assertSame($endpoint, $exception->endpoint());
            $this->assertSame($restartAuthorization, $exception->restartAuthorization());
            $this->assertInstanceOf(FatalRequestException::class, $exception->getPrevious());
            $this->assertStringNotContainsString('connection-secret', $exception->getMessage());
        }

        $this->assertSame($expectedAttempts, $attempts);
    }

    /** @return iterable<string, array{int}> */
    public static function retryableStatuses(): iterable
    {
        yield 'too many requests' => [429];
        yield 'bad gateway' => [502];
        yield 'service unavailable' => [503];
        yield 'gateway timeout' => [504];
    }

    #[DataProvider('retryableStatuses')]
    public function test_safe_reads_retry_only_the_allowed_http_statuses(int $status): void
    {
        $mockClient = MockClient::global([
            TestTransportRequest::class => MockResponse::make([], $status),
        ]);

        $this->expectException(MyinfoV6TransportException::class);

        try {
            (new MyinfoV6RequestSender)->send(
                new TestTransportRequest(true),
                'discovery',
                false,
            );
        } finally {
            $mockClient->assertSentCount(2, TestTransportRequest::class);
        }
    }

    public function test_non_retryable_http_errors_are_returned_after_one_attempt(): void
    {
        $mockClient = MockClient::global([
            TestTransportRequest::class => MockResponse::make(['error' => 'protocol-secret'], 400),
        ]);

        $response = (new MyinfoV6RequestSender)->send(
            new TestTransportRequest(true),
            'discovery',
            false,
        );

        $this->assertSame(400, $response->status());
        $mockClient->assertSentCount(1, TestTransportRequest::class);
    }

    public function test_safe_transport_exception_output_excludes_diagnostic_secrets(): void
    {
        $exception = new MyinfoV6TransportException(
            'token',
            true,
            new RuntimeException('sentinel-authorization-code-secret'),
        );

        ob_start();
        var_dump($exception);
        $debugOutput = (string) ob_get_clean();
        $json = json_encode($exception, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('sentinel-authorization-code-secret', $exception->getMessage());
        $this->assertStringNotContainsString('sentinel-authorization-code-secret', $debugOutput);
        $this->assertStringNotContainsString('sentinel-authorization-code-secret', $json);
        $this->assertSame(
            '{"message":"The MyInfo V6 transport request could not be completed.","endpoint":"token","restart_authorization":true}',
            $json,
        );
    }

    public function test_transport_exception_cannot_serialize_its_previous_request_context(): void
    {
        $exception = new MyinfoV6TransportException(
            'userinfo',
            false,
            new RuntimeException('private-token-secret'),
        );

        $this->expectException(LogicException::class);

        serialize($exception);
    }

    public function test_par_and_token_requests_never_retry_retryable_responses(): void
    {
        $clientSigningKey = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
            'kid' => 'transport-client-signing-key',
        ]);
        $dpopPrivateKey = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
        ]);
        config()->set('laravel-myinfo-sg-v6.client_id', 'transport-client-id');
        config()->set('laravel-myinfo-sg-v6.redirect_uri', 'https://client.example/callback');
        config()->set('laravel-myinfo-sg-v6.scopes', 'openid');
        config()->set('laravel-myinfo-sg-v6.chosen_jwks_sig_kid', 'transport-client-signing-key');
        config()->set('laravel-myinfo-sg-v6.private_jwks', json_encode([
            'keys' => [$clientSigningKey->jsonSerialize()],
        ], JSON_THROW_ON_ERROR));
        $mockClient = MockClient::global([
            PushedAuthorizationRequest::class => MockResponse::make([], 503),
            GetAccessTokenRequest::class => MockResponse::make([], 503),
        ]);
        $requests = [
            'par' => new PushedAuthorizationRequest(
                'https://provider.example/par',
                'https://provider.example',
                $dpopPrivateKey,
                'state',
                'nonce',
                'challenge',
            ),
            'token' => new GetAccessTokenRequest(
                'https://provider.example/token',
                'authorization-code-secret',
                'https://provider.example',
                'https://client.example/callback',
                'code-verifier-secret',
                $dpopPrivateKey,
            ),
        ];

        foreach ($requests as $endpoint => $request) {
            try {
                (new MyinfoV6RequestSender)->send($request, $endpoint, true);
                $this->fail("Expected the {$endpoint} response to fail.");
            } catch (MyinfoV6TransportException $exception) {
                $this->assertSame($endpoint, $exception->endpoint());
                $this->assertTrue($exception->restartAuthorization());
            }
        }

        $mockClient->assertSentCount(1, PushedAuthorizationRequest::class);
        $mockClient->assertSentCount(1, GetAccessTokenRequest::class);
    }
}

final class TestTransportRequest extends MyinfoV6Request
{
    protected Method $method = Method::GET;

    public function __construct(bool $safeRead)
    {
        parent::__construct();

        if ($safeRead) {
            $this->enableSafeReadRetries();
        }
    }

    public function resolveEndpoint(): string
    {
        return 'https://provider.example/transport-test';
    }
}
