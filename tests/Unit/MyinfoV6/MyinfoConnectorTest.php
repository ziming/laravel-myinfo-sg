<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use RuntimeException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\AuthorizationTransaction;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\ValidatedAuthorizationCallback;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\PushedAuthorizationRequest;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationTransactionStore;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class MyinfoConnectorTest extends TestCase
{
    private string $privateDpopJwkJson;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        CarbonImmutable::setTestNow('2026-08-27 10:00:00');
        config()->set('laravel-myinfo-sg-v6.issuer_uri', 'https://stg-id.singpass.gov.sg');
        config()->set('laravel-myinfo-sg-v6.client_id', 'test-client-id');
        config()->set('laravel-myinfo-sg-v6.redirect_uri', 'https://client.example/callback');
        config()->set('laravel-myinfo-sg-v6.transaction_session_key', 'test_myinfo_transactions');
        config()->set('laravel-myinfo-sg-v6.transaction_ttl_seconds', 600);

        $clientSigningJwk = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
            'kid' => 'client-signing-key',
        ]);
        config()->set('laravel-myinfo-sg-v6.chosen_jwks_sig_kid', 'client-signing-key');
        config()->set('laravel-myinfo-sg-v6.private_jwks', json_encode([
            'keys' => [$clientSigningJwk->jsonSerialize()],
        ], JSON_THROW_ON_ERROR));

        $this->privateDpopJwkJson = json_encode(
            JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig']),
            JSON_THROW_ON_ERROR,
        );
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_get_stored_dpop_key_pair_returns_same_key_pair(): void
    {
        $connector = new MyinfoConnector;
        $getStoredDpopKeyPair = \Closure::bind(
            fn (): array => $this->getStoredDpopKeyPair(),
            $connector,
            MyinfoConnector::class
        );
        session()->put(
            config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key'),
            $this->privateDpopJwkJson,
        );

        $createdPrivateJwk = JWKFactory::createFromJsonObject($this->privateDpopJwkJson);
        $this->assertInstanceOf(JWK::class, $createdPrivateJwk);
        $createdPublicJwk = $createdPrivateJwk->toPublic();
        [$storedPrivateJwk, $storedPublicJwk] = $getStoredDpopKeyPair();

        $this->assertSame($createdPrivateJwk->get('x'), $storedPrivateJwk->get('x'));
        $this->assertSame($createdPrivateJwk->get('d'), $storedPrivateJwk->get('d'));
        $this->assertSame($createdPublicJwk->get('x'), $storedPublicJwk->get('x'));
        $this->assertSame($createdPublicJwk->get('y'), $storedPublicJwk->get('y'));
    }

    public function test_get_stored_dpop_key_pair_throws_when_missing_from_session(): void
    {
        $connector = new MyinfoConnector;
        $getStoredDpopKeyPair = \Closure::bind(
            fn (): array => $this->getStoredDpopKeyPair(),
            $connector,
            MyinfoConnector::class
        );

        session()->forget(config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No DPoP private key found in session');

        $getStoredDpopKeyPair();
    }

    public function test_generate_authorization_url_stores_concurrent_transactions_from_validated_discovery(): void
    {
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            PushedAuthorizationRequest::class => MockResponse::make(['request_uri' => 'urn:example:par-request']),
        ]);
        $connector = new MyinfoConnector;

        $firstUrl = $connector->generateAuthorizationUrl();
        $secondUrl = $connector->generateAuthorizationUrl('https://client.example/alternate-callback');

        $this->assertSame(
            'https://stg-id.singpass.gov.sg/fapi/auth?client_id=test-client-id&request_uri=urn%3Aexample%3Apar-request',
            $firstUrl,
        );
        $this->assertSame(
            'https://stg-id.singpass.gov.sg/fapi/auth?client_id=test-client-id&request_uri=urn%3Aexample%3Apar-request',
            $secondUrl,
        );

        $records = session()->get('test_myinfo_transactions');
        $this->assertIsArray($records);
        $this->assertCount(2, $records);
        $this->assertEqualsCanonicalizing(
            ['https://client.example/alternate-callback', 'https://client.example/callback'],
            array_values(array_unique(array_column($records, 'redirect_uri'))),
        );
        $this->assertSame(
            ['https://stg-id.singpass.gov.sg/fapi'],
            array_values(array_unique(array_column($records, 'issuer'))),
        );
        $this->assertNull(session(config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key')));
        $mockClient->assertSentCount(2, PushedAuthorizationRequest::class);
    }

    public function test_generate_authorization_url_rejects_mismatched_discovery_issuer_before_par(): void
    {
        $metadata = $this->metadata();
        $metadata['issuer'] = 'https://unexpected.example/fapi';
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($metadata),
            PushedAuthorizationRequest::class => MockResponse::make(['request_uri' => 'must-not-be-used']),
        ]);

        try {
            (new MyinfoConnector)->generateAuthorizationUrl();
            $this->fail('Expected mismatched discovery issuer to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Singpass discovery issuer does not match the configured issuer.',
                $exception->getMessage(),
            );
        }

        $mockClient->assertSentCount(1, GetSingpassOpenIdConfigurationRequest::class);
        $mockClient->assertSentCount(0, PushedAuthorizationRequest::class);
    }

    public function test_connector_validates_callback_against_the_request_session(): void
    {
        $transaction = $this->transaction('callback-state');
        (new AuthorizationTransactionStore(session()->driver()))->put($transaction);
        $request = Request::create('/callback', 'GET', [
            'state' => 'callback-state',
            'iss' => $transaction->issuer,
            'code' => 'callback-code',
        ]);
        $request->setLaravelSession(session()->driver());

        $validated = (new MyinfoConnector)->validateAuthorizationCallback($request);

        $this->assertSame('callback-code', $validated->code);
        $this->assertSame('callback-state', $validated->transaction->state);
    }

    public function test_secure_token_exchange_uses_validated_transaction_without_scalar_session_values(): void
    {
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetAccessTokenRequest::class => MockResponse::make([
                'access_token' => 'raw-access-token',
                'id_token' => 'raw-id-token',
            ]),
        ]);
        session()->forget([
            config('laravel-myinfo-sg-v6.code_verifier_session_key'),
            config('laravel-myinfo-sg-v6.redirect_uri_session_key'),
            config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key'),
        ]);
        $callback = new ValidatedAuthorizationCallback(
            'validated-code',
            $this->transaction('validated-state', 'transaction-code-verifier'),
        );

        $response = (new MyinfoConnector)->getAccessTokenFromValidatedCallback($callback);

        $this->assertSame('raw-access-token', $response['access_token']);
        $mockClient->assertSent(function ($request, $response): bool {
            if (! $request instanceof GetAccessTokenRequest) {
                return false;
            }

            $body = $response->getPendingRequest()->body()?->all();

            return is_array($body)
                && $body['code'] === 'validated-code'
                && $body['redirect_uri'] === 'https://client.example/callback'
                && $body['code_verifier'] === 'transaction-code-verifier';
        });
    }

    public function test_low_level_token_exchange_can_consume_the_latest_transaction_without_duplicating_private_material(): void
    {
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            PushedAuthorizationRequest::class => MockResponse::make(['request_uri' => 'urn:example:legacy-par']),
            GetAccessTokenRequest::class => MockResponse::make(['access_token' => 'legacy-access-token']),
        ]);
        $connector = new MyinfoConnector;
        $connector->generateAuthorizationUrl();

        $this->assertNull(session(config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key')));

        $response = $connector->getAccessToken('legacy-code');

        $this->assertSame('legacy-access-token', $response['access_token']);
        $this->assertSame([], session()->get('test_myinfo_transactions'));
        $this->assertIsString(session(config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key')));
        $mockClient->assertSentCount(1, GetAccessTokenRequest::class);
    }

    /**
     * @return array<string, string>
     */
    private function metadata(): array
    {
        return [
            'issuer' => 'https://stg-id.singpass.gov.sg/fapi',
            'authorization_endpoint' => 'https://stg-id.singpass.gov.sg/fapi/auth',
            'pushed_authorization_request_endpoint' => 'https://stg-id.singpass.gov.sg/fapi/par',
            'token_endpoint' => 'https://stg-id.singpass.gov.sg/fapi/token',
            'userinfo_endpoint' => 'https://stg-id.singpass.gov.sg/fapi/userinfo',
            'jwks_uri' => 'https://stg-id.singpass.gov.sg/.well-known/keys',
        ];
    }

    private function transaction(
        string $state,
        string $codeVerifier = 'code-verifier',
    ): AuthorizationTransaction {
        return new AuthorizationTransaction(
            $state,
            'nonce-'.$state,
            $codeVerifier,
            'https://client.example/callback',
            'https://stg-id.singpass.gov.sg/fapi',
            $this->privateDpopJwkJson,
            CarbonImmutable::now()->timestamp,
        );
    }
}
