<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\AuthorizationTransaction;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\AuthorizationResponseException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidAuthorizationCallbackException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidIdTokenException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassJwksRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationTransactionStore;
use Ziming\LaravelMyinfoSg\Tests\TestCase;
use Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6\Support\NestedTokenFactory;

class CompleteAuthorizationTest extends TestCase
{
    private const string ISSUER = 'https://stg-id.singpass.gov.sg/fapi';

    private const string CLIENT_ID = 'test-client-id';

    private const int NOW = 1787805600;

    private JWK $clientSigningKey;

    private JWK $decryptionKey;

    private JWK $singpassSigningKey;

    private string $dpopPrivateJwkJson;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        CarbonImmutable::setTestNow('@'.self::NOW);
        Clock::set(new MockClock('@'.self::NOW));
        config()->set('laravel-myinfo-sg-v6.issuer_uri', 'https://stg-id.singpass.gov.sg');
        config()->set('laravel-myinfo-sg-v6.client_id', self::CLIENT_ID);
        config()->set('laravel-myinfo-sg-v6.redirect_uri', 'https://client.example/callback');
        config()->set('laravel-myinfo-sg-v6.transaction_session_key', 'test_myinfo_transactions');
        config()->set('laravel-myinfo-sg-v6.transaction_ttl_seconds', 600);

        $this->clientSigningKey = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
            'kid' => 'client-signing-key',
        ]);
        $this->decryptionKey = NestedTokenFactory::encryptionKey();
        $this->singpassSigningKey = NestedTokenFactory::signingKey();
        $this->dpopPrivateJwkJson = json_encode(
            JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig']),
            JSON_THROW_ON_ERROR,
        );

        config()->set('laravel-myinfo-sg-v6.chosen_jwks_sig_kid', 'client-signing-key');
        config()->set('laravel-myinfo-sg-v6.private_jwks', json_encode([
            'keys' => [
                $this->clientSigningKey->jsonSerialize(),
                $this->decryptionKey->jsonSerialize(),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();
        CarbonImmutable::setTestNow();
        Clock::set(new NativeClock);

        parent::tearDown();
    }

    public function test_completes_authorization_and_returns_only_verified_values(): void
    {
        $state = 'happy-state';
        $transaction = $this->storeTransaction($state);
        $idToken = $this->idToken($this->validClaims($transaction));
        $mockClient = $this->mockFlow([
            'access_token' => 'verified-access-token',
            'id_token' => $idToken,
            'token_type' => 'DPoP',
        ]);

        $tokenSet = (new MyinfoConnector)->completeAuthorization($this->callbackRequest($transaction));

        $this->assertSame('verified-access-token', $tokenSet->accessToken());
        $this->assertSame('S1234567A', $tokenSet->subject());
        $this->assertSame('DPoP', $tokenSet->tokenType());
        $this->assertSame($this->validClaims($transaction), $tokenSet->claims());
        $this->assertSame('{}', json_encode($tokenSet, JSON_THROW_ON_ERROR));

        $debugOutput = print_r($tokenSet, true);
        $this->assertStringContainsString('[redacted]', $debugOutput);
        $this->assertStringNotContainsString('verified-access-token', $debugOutput);
        $this->assertStringNotContainsString(
            (string) $transaction->dpopPrivateJwk()->get('d'),
            $debugOutput,
        );
        $this->assertStringNotContainsString(
            (string) $transaction->dpopPrivateJwk()->get('d'),
            var_export($tokenSet, true),
        );

        $mockClient->assertSent(function ($request, $response): bool {
            if (! $request instanceof GetAccessTokenRequest) {
                return false;
            }

            $body = $response->getPendingRequest()->body()?->all();

            return is_array($body)
                && $body['code'] === 'authorization-code'
                && $body['redirect_uri'] === 'https://client.example/callback'
                && $body['code_verifier'] === 'transaction-code-verifier';
        });

        $this->expectException(LogicException::class);
        serialize($tokenSet);
    }

    public function test_invalid_callback_fails_before_any_endpoint_request(): void
    {
        $transaction = $this->storeTransaction('expected-state');
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetAccessTokenRequest::class => MockResponse::make([]),
            GetSingpassJwksRequest::class => MockResponse::make([]),
        ]);
        $request = $this->callbackRequest($transaction, state: 'wrong-state');

        try {
            (new MyinfoConnector)->completeAuthorization($request);
            $this->fail('Expected invalid callback to fail.');
        } catch (InvalidAuthorizationCallbackException) {
            $this->assertTrue(true);
        }

        $mockClient->assertSentCount(0);
    }

    /**
     * @return iterable<string, array{mixed, int, string}>
     */
    public static function invalidTokenResponses(): iterable
    {
        yield 'non-2xx OAuth response' => [
            ['error' => 'invalid_grant', 'error_description' => 'must stay secret'],
            400,
            'invalid_grant',
        ];
        yield 'OAuth error in a success response' => [
            ['error' => 'invalid_request', 'error_description' => 'must stay secret'],
            200,
            'invalid_request',
        ];
        yield 'missing access token' => [
            ['id_token' => 'token', 'token_type' => 'DPoP'],
            200,
            'invalid_token_response',
        ];
        yield 'missing ID token' => [
            ['access_token' => 'token', 'token_type' => 'DPoP'],
            200,
            'invalid_token_response',
        ];
        yield 'empty access token' => [
            ['access_token' => ' ', 'id_token' => 'token', 'token_type' => 'DPoP'],
            200,
            'invalid_token_response',
        ];
        yield 'empty ID token' => [
            ['access_token' => 'token', 'id_token' => '', 'token_type' => 'DPoP'],
            200,
            'invalid_token_response',
        ];
        yield 'wrong token type' => [
            ['access_token' => 'token', 'id_token' => 'token', 'token_type' => 'Bearer'],
            200,
            'invalid_token_response',
        ];
        yield 'case-mismatched token type' => [
            ['access_token' => 'token', 'id_token' => 'token', 'token_type' => 'dpop'],
            200,
            'invalid_token_response',
        ];
        yield 'non-object JSON response' => [
            ['token'],
            200,
            'invalid_token_response',
        ];
        yield 'malformed JSON response' => [
            '{not-json',
            200,
            'invalid_token_response',
        ];
    }

    #[DataProvider('invalidTokenResponses')]
    public function test_rejects_invalid_token_response_envelopes(
        mixed $body,
        int $status,
        string $expectedErrorCode,
    ): void {
        $transaction = $this->storeTransaction('envelope-state');
        $mockClient = $this->mockFlow($body, $status);

        try {
            (new MyinfoConnector)->completeAuthorization($this->callbackRequest($transaction));
            $this->fail('Expected invalid token endpoint response to fail.');
        } catch (AuthorizationResponseException $exception) {
            $this->assertSame($expectedErrorCode, $exception->errorCode);
            $this->assertSame('The authorization provider returned an error.', $exception->getMessage());
            $this->assertStringNotContainsString('must stay secret', $exception->getMessage());
        }

        $mockClient->assertSentCount(0, GetSingpassJwksRequest::class);
    }

    public function test_rejects_an_id_token_encrypted_for_a_different_key(): void
    {
        $transaction = $this->storeTransaction('decryption-state');
        $wrongKey = NestedTokenFactory::encryptionKey(kid: $this->decryptionKey->get('kid'));
        $idToken = NestedTokenFactory::idToken(
            $this->validClaims($transaction),
            $wrongKey,
            $this->singpassSigningKey,
        );

        $mockClient = $this->assertInvalidIdToken($transaction, $idToken);

        $mockClient->assertSentCount(1, GetSingpassJwksRequest::class);
    }

    public function test_rejects_an_id_token_with_the_wrong_signature(): void
    {
        $transaction = $this->storeTransaction('signature-state');
        $wrongSigningKey = NestedTokenFactory::signingKey(kid: 'wrong-signing-key');
        $idToken = NestedTokenFactory::idToken(
            $this->validClaims($transaction),
            $this->decryptionKey,
            $wrongSigningKey,
            innerHeaders: ['kid' => $this->singpassSigningKey->get('kid')],
        );

        $mockClient = $this->assertInvalidIdToken($transaction, $idToken);

        $mockClient->assertSentCount(2, GetSingpassJwksRequest::class);
    }

    public function test_rejects_an_id_token_with_the_wrong_transaction_nonce(): void
    {
        $transaction = $this->storeTransaction('nonce-state');
        $claims = $this->validClaims($transaction);
        $claims['nonce'] = 'nonce-from-another-transaction';

        $mockClient = $this->assertInvalidIdToken($transaction, $this->idToken($claims));

        $mockClient->assertSentCount(1, GetSingpassJwksRequest::class);
    }

    public function test_refreshes_cached_jwks_once_for_a_rotated_id_token_signing_key(): void
    {
        $transaction = $this->storeTransaction('rotated-key-state');
        $rotatedSigningKey = NestedTokenFactory::signingKey(kid: 'rotated-signing-key');
        $idToken = NestedTokenFactory::idToken(
            $this->validClaims($transaction),
            $this->decryptionKey,
            $rotatedSigningKey,
        );
        $jwksCalls = 0;
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetAccessTokenRequest::class => MockResponse::make([
                'access_token' => 'rotated-access-token',
                'id_token' => $idToken,
                'token_type' => 'DPoP',
            ]),
            GetSingpassJwksRequest::class => function () use (&$jwksCalls, $rotatedSigningKey): MockResponse {
                $jwksCalls++;
                $key = $jwksCalls === 1 ? $this->singpassSigningKey : $rotatedSigningKey;

                return MockResponse::make([
                    'keys' => [$key->toPublic()->jsonSerialize()],
                ]);
            },
        ]);

        $tokenSet = (new MyinfoConnector)->completeAuthorization($this->callbackRequest($transaction));

        $this->assertSame('rotated-access-token', $tokenSet->accessToken());
        $this->assertSame(2, $jwksCalls);
        $mockClient->assertSentCount(2, GetSingpassJwksRequest::class);
    }

    public function test_stops_after_one_forced_jwks_refresh_when_the_signing_key_is_still_unknown(): void
    {
        $transaction = $this->storeTransaction('still-unknown-key-state');
        $unknownSigningKey = NestedTokenFactory::signingKey(kid: 'still-unknown-signing-key');
        $idToken = NestedTokenFactory::idToken(
            $this->validClaims($transaction),
            $this->decryptionKey,
            $unknownSigningKey,
        );
        $mockClient = $this->mockFlow([
            'access_token' => 'must-not-be-returned',
            'id_token' => $idToken,
            'token_type' => 'DPoP',
        ]);

        try {
            (new MyinfoConnector)->completeAuthorization($this->callbackRequest($transaction));
            $this->fail('Expected the unknown signing key to remain invalid after one refresh.');
        } catch (InvalidIdTokenException $exception) {
            $this->assertSame('The ID token is invalid.', $exception->getMessage());
        }

        $mockClient->assertSentCount(2, GetSingpassJwksRequest::class);
    }

    private function storeTransaction(string $state): AuthorizationTransaction
    {
        $transaction = new AuthorizationTransaction(
            $state,
            'nonce-'.$state,
            'transaction-code-verifier',
            'https://client.example/callback',
            self::ISSUER,
            $this->dpopPrivateJwkJson,
            self::NOW,
        );
        (new AuthorizationTransactionStore(session()->driver()))->put($transaction);

        return $transaction;
    }

    private function callbackRequest(
        AuthorizationTransaction $transaction,
        ?string $state = null,
    ): Request {
        $request = Request::create('/callback', 'GET', [
            'state' => $state ?? $transaction->state,
            'iss' => $transaction->issuer,
            'code' => 'authorization-code',
        ]);
        $request->setLaravelSession(session()->driver());

        return $request;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function idToken(array $claims): string
    {
        return NestedTokenFactory::idToken(
            $claims,
            $this->decryptionKey,
            $this->singpassSigningKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validClaims(AuthorizationTransaction $transaction): array
    {
        return [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'exp' => self::NOW + 300,
            'iat' => self::NOW,
            'nonce' => $transaction->nonce,
            'sub' => 'S1234567A',
        ];
    }

    private function mockFlow(mixed $tokenResponse, int $status = 200): MockClient
    {
        return MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetAccessTokenRequest::class => MockResponse::make($tokenResponse, $status),
            GetSingpassJwksRequest::class => MockResponse::make([
                'keys' => [$this->singpassSigningKey->toPublic()->jsonSerialize()],
            ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function metadata(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'pushed_authorization_request_endpoint' => self::ISSUER.'/par',
            'token_endpoint' => self::ISSUER.'/token',
            'userinfo_endpoint' => self::ISSUER.'/userinfo',
            'jwks_uri' => 'https://stg-id.singpass.gov.sg/.well-known/keys',
            'dpop_signing_alg_values_supported' => ['ES256', 'ES384', 'ES512'],
        ];
    }

    private function assertInvalidIdToken(
        AuthorizationTransaction $transaction,
        string $idToken,
    ): MockClient
    {
        $mockClient = $this->mockFlow([
            'access_token' => 'must-not-be-returned',
            'id_token' => $idToken,
            'token_type' => 'DPoP',
        ]);

        try {
            (new MyinfoConnector)->completeAuthorization($this->callbackRequest($transaction));
            $this->fail('Expected invalid ID token to fail.');
        } catch (InvalidIdTokenException $exception) {
            $this->assertStringNotContainsString($idToken, $exception->getMessage());
            $this->assertStringNotContainsString(
                (string) $this->decryptionKey->get('d'),
                $exception->getMessage(),
            );
        }

        return $mockClient;
    }
}
