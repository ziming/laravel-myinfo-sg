<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Ziming\LaravelMyinfoSg\Data\MyinfoV5\AuthorizationTransaction;
use Ziming\LaravelMyinfoSg\Data\MyinfoV5\ValidatedAuthorizationCallback;
use Ziming\LaravelMyinfoSg\Data\MyinfoV5\VerifiedTokenSet;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5\InvalidUserInfoException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\MyinfoConnector;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetSingpassJwksRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetUserRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\PushedAuthorizationRequest;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\AuthorizationTransactionStore;
use Ziming\LaravelMyinfoSg\Tests\TestCase;
use Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5\Support\NestedTokenFactory;

class MyinfoConnectorTest extends TestCase
{
    private string $privateDpopJwkJson;

    private JWK $decryptionKey;

    private JWK $singpassSigningKey;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        CarbonImmutable::setTestNow('2026-08-27 10:00:00');
        Clock::set(new MockClock('@'.CarbonImmutable::now()->timestamp));
        config()->set('laravel-myinfo-sg-v5.issuer_uri', 'https://stg-id.singpass.gov.sg');
        config()->set('laravel-myinfo-sg-v5.client_id', 'test-client-id');
        config()->set('laravel-myinfo-sg-v5.redirect_uri', 'https://client.example/callback');
        config()->set('laravel-myinfo-sg-v5.transaction_session_key', 'test_myinfo_transactions');
        config()->set('laravel-myinfo-sg-v5.transaction_ttl_seconds', 600);
        config()->set('laravel-myinfo-sg-v5.safe_read_retry_delay_milliseconds', 0);

        $clientSigningJwk = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
            'kid' => 'client-signing-key',
        ]);
        config()->set('laravel-myinfo-sg-v5.chosen_jwks_sig_kid', 'client-signing-key');
        $this->decryptionKey = NestedTokenFactory::encryptionKey();
        $this->singpassSigningKey = NestedTokenFactory::signingKey();
        config()->set('laravel-myinfo-sg-v5.private_jwks', json_encode([
            'keys' => [
                $clientSigningJwk->jsonSerialize(),
                $this->decryptionKey->jsonSerialize(),
            ],
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
        Clock::set(new NativeClock);

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
            config('laravel-myinfo-sg-v5.dpop_private_jwk_session_key'),
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

        session()->forget(config('laravel-myinfo-sg-v5.dpop_private_jwk_session_key'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No DPoP private key found in session');

        $getStoredDpopKeyPair();
    }

    public function test_generate_authorization_url_stores_concurrent_transactions_from_validated_discovery(): void
    {
        $this->assertSame('ES256', config('laravel-myinfo-sg-v5.dpop_signing_alg'));
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
        $this->assertSame(['ES256'], array_values(array_unique(array_column($records, 'dpop_signing_alg'))));
        $firstKey = JWKFactory::createFromJsonObject($records[array_key_first($records)]['dpop_private_jwk']);
        $lastKey = JWKFactory::createFromJsonObject($records[array_key_last($records)]['dpop_private_jwk']);
        $this->assertInstanceOf(JWK::class, $firstKey);
        $this->assertInstanceOf(JWK::class, $lastKey);
        $this->assertNotSame($firstKey->thumbprint('sha256'), $lastKey->thumbprint('sha256'));
        $this->assertNull(session(config('laravel-myinfo-sg-v5.dpop_private_jwk_session_key')));
        $mockClient->assertSentCount(2, PushedAuthorizationRequest::class);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function dpopProfiles(): iterable
    {
        yield 'ES256 / P-256' => ['ES256', 'P-256', 'ES384'];
        yield 'ES384 / P-384' => ['ES384', 'P-384', 'ES512'];
        yield 'ES512 / P-521' => ['ES512', 'P-521', 'ES256'];
    }

    #[DataProvider('dpopProfiles')]
    public function test_each_configured_profile_reuses_one_transaction_key_with_fresh_proofs(
        string $algorithm,
        string $curve,
        string $changedConfiguration,
    ): void {
        config()->set('laravel-myinfo-sg-v5.dpop_signing_alg', $algorithm);
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            PushedAuthorizationRequest::class => MockResponse::make(['request_uri' => 'urn:example:profile-par']),
            GetAccessTokenRequest::class => MockResponse::make(['access_token' => 'profile-access-token']),
            GetUserRequest::class => MockResponse::make('userinfo-response'),
        ]);
        $connector = new MyinfoConnector;

        $connector->generateAuthorizationUrl();
        config()->set('laravel-myinfo-sg-v5.dpop_signing_alg', $changedConfiguration);
        $connector->getAccessToken('profile-code');
        $connector->getUser('profile-access-token');

        $proofs = [];

        foreach ($mockClient->getRecordedResponses() as $response) {
            $request = $response->getPendingRequest()->getRequest();
            $name = match (true) {
                $request instanceof PushedAuthorizationRequest => 'par',
                $request instanceof GetAccessTokenRequest => 'token',
                $request instanceof GetUserRequest => 'userinfo',
                default => null,
            };

            if ($name === null) {
                continue;
            }

            $proof = $response->getPendingRequest()->headers()->get('DPoP');
            $this->assertIsString($proof);
            $proofs[$name] = $this->decodeDpopProof($proof);
        }

        $this->assertSame(['par', 'token', 'userinfo'], array_keys($proofs));
        $thumbprints = array_map(
            static fn (array $proof): string => (new JWK($proof['header']['jwk']))->thumbprint('sha256'),
            $proofs,
        );
        $this->assertCount(1, array_unique($thumbprints));
        $this->assertCount(3, array_unique(array_column(array_column($proofs, 'payload'), 'jti')));

        foreach ($proofs as $proof) {
            $this->assertSame($algorithm, $proof['header']['alg']);
            $this->assertSame($curve, $proof['header']['jwk']['crv']);
            $this->assertArrayNotHasKey('d', $proof['header']['jwk']);
        }

        $this->assertSame('POST', $proofs['par']['payload']['htm']);
        $this->assertSame('https://stg-id.singpass.gov.sg/fapi/par', $proofs['par']['payload']['htu']);
        $this->assertArrayNotHasKey('ath', $proofs['par']['payload']);
        $this->assertSame('POST', $proofs['token']['payload']['htm']);
        $this->assertSame('https://stg-id.singpass.gov.sg/fapi/token', $proofs['token']['payload']['htu']);
        $this->assertArrayNotHasKey('ath', $proofs['token']['payload']);
        $this->assertSame('GET', $proofs['userinfo']['payload']['htm']);
        $this->assertSame('https://stg-id.singpass.gov.sg/fapi/userinfo', $proofs['userinfo']['payload']['htu']);
        $this->assertSame(
            $this->accessTokenHash('profile-access-token'),
            $proofs['userinfo']['payload']['ath'],
        );
    }

    public function test_invalid_configured_dpop_algorithm_fails_before_discovery_or_par(): void
    {
        config()->set('laravel-myinfo-sg-v5.dpop_signing_alg', 'RS256');
        $metadata = $this->metadata();
        $metadata['dpop_signing_alg_values_supported'] = ['RS256'];
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($metadata),
            PushedAuthorizationRequest::class => MockResponse::make(['request_uri' => 'must-not-be-used']),
        ]);

        try {
            (new MyinfoConnector)->generateAuthorizationUrl();
            $this->fail('Expected the local DPoP policy to reject the algorithm.');
        } catch (RuntimeException $exception) {
            $this->assertSame('MyInfo V5 DPoP signing algorithm is invalid.', $exception->getMessage());
        }

        $mockClient->assertSentCount(0, GetSingpassOpenIdConfigurationRequest::class);
        $mockClient->assertSentCount(0, PushedAuthorizationRequest::class);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompatibleDpopMetadata(): iterable
    {
        yield 'missing field' => [[]];
        yield 'selected algorithm excluded' => [['dpop_signing_alg_values_supported' => ['ES256']]];
    }

    #[DataProvider('incompatibleDpopMetadata')]
    public function test_discovery_must_advertise_the_selected_dpop_algorithm(array $override): void
    {
        config()->set('laravel-myinfo-sg-v5.dpop_signing_alg', 'ES384');
        $metadata = $this->metadata();

        if ($override === []) {
            unset($metadata['dpop_signing_alg_values_supported']);
        } else {
            $metadata = [...$metadata, ...$override];
        }

        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($metadata),
            PushedAuthorizationRequest::class => MockResponse::make(['request_uri' => 'must-not-be-used']),
        ]);

        try {
            (new MyinfoConnector)->generateAuthorizationUrl();
            $this->fail('Expected incompatible discovery metadata to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Singpass discovery is incompatible with the selected DPoP signing algorithm.',
                $exception->getMessage(),
            );
        }

        $mockClient->assertSentCount(1, GetSingpassOpenIdConfigurationRequest::class);
        $mockClient->assertSentCount(0, PushedAuthorizationRequest::class);
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
            config('laravel-myinfo-sg-v5.code_verifier_session_key'),
            config('laravel-myinfo-sg-v5.redirect_uri_session_key'),
            config('laravel-myinfo-sg-v5.dpop_private_jwk_session_key'),
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

        $this->assertNull(session(config('laravel-myinfo-sg-v5.dpop_private_jwk_session_key')));

        $response = $connector->getAccessToken('legacy-code');

        $this->assertSame('legacy-access-token', $response['access_token']);
        $this->assertSame([], session()->get('test_myinfo_transactions'));
        $this->assertIsString(session(config('laravel-myinfo-sg-v5.dpop_private_jwk_session_key')));
        $mockClient->assertSentCount(1, GetAccessTokenRequest::class);
    }

    public function test_verified_userinfo_uses_the_token_set_dpop_context_and_binds_the_subject(): void
    {
        $boundDpopKey = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
        ]);
        $tokenSet = new VerifiedTokenSet(
            'verified-access-token',
            ['sub' => 'verified-subject'],
            'DPoP',
            $boundDpopKey,
        );
        $claims = $this->validUserInfoClaims();
        $compactUserInfo = NestedTokenFactory::userInfo(
            $claims,
            $this->decryptionKey,
            $this->singpassSigningKey,
        );
        session()->put(
            config('laravel-myinfo-sg-v5.dpop_private_jwk_session_key'),
            'invalid-session-global-key',
        );
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetUserRequest::class => MockResponse::make($compactUserInfo),
            GetSingpassJwksRequest::class => MockResponse::make([
                'keys' => [$this->singpassSigningKey->toPublic()->jsonSerialize()],
            ]),
        ]);

        $userInfo = (new MyinfoConnector)->getVerifiedUserInfo($tokenSet);

        $this->assertSame($claims, $userInfo->claims());
        $this->assertSame($claims['person_info'], $userInfo->personInfo());
        $mockClient->assertSent(function ($request, $response) use ($boundDpopKey): bool {
            if (! $request instanceof GetUserRequest) {
                return false;
            }

            $headers = $response->getPendingRequest()->headers()->all();
            $proof = $headers['DPoP'] ?? null;

            if (! is_string($proof)) {
                return false;
            }

            [$encodedHeader, $encodedPayload] = explode('.', $proof, 3);
            $header = json_decode($this->decodeBase64Url($encodedHeader), true, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($this->decodeBase64Url($encodedPayload), true, 512, JSON_THROW_ON_ERROR);

            return ($headers['Authorization'] ?? null) === 'DPoP verified-access-token'
                && $header['jwk']['x'] === $boundDpopKey->toPublic()->get('x')
                && $header['jwk']['y'] === $boundDpopKey->toPublic()->get('y')
                && ! array_key_exists('d', $header['jwk'])
                && $payload['ath'] === $this->accessTokenHash('verified-access-token');
        });
    }

    public function test_verified_userinfo_retry_uses_a_fresh_proof_for_the_same_token_and_key(): void
    {
        $tokenSet = $this->verifiedTokenSet();
        $compactUserInfo = NestedTokenFactory::userInfo(
            $this->validUserInfoClaims(),
            $this->decryptionKey,
            $this->singpassSigningKey,
        );
        $userInfoAttempts = 0;
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetUserRequest::class => function () use (&$userInfoAttempts, $compactUserInfo): MockResponse {
                $userInfoAttempts++;

                return $userInfoAttempts === 1
                    ? MockResponse::make(['error' => 'temporarily_unavailable'], 503)
                    : MockResponse::make($compactUserInfo);
            },
            GetSingpassJwksRequest::class => MockResponse::make([
                'keys' => [$this->singpassSigningKey->toPublic()->jsonSerialize()],
            ]),
        ]);

        $userInfo = (new MyinfoConnector)->getVerifiedUserInfo($tokenSet);

        $this->assertSame('verified-subject', $userInfo->subject());
        $this->assertSame(2, $userInfoAttempts);

        $proofs = [];

        foreach ($mockClient->getRecordedResponses() as $response) {
            if (! ($response->getPendingRequest()->getRequest() instanceof GetUserRequest)) {
                continue;
            }

            $proof = $response->getPendingRequest()->headers()->get('DPoP');
            $this->assertIsString($proof);
            $proofs[] = $this->decodeDpopProof($proof);
        }

        $this->assertCount(2, $proofs);
        $this->assertSame(
            (new JWK($proofs[0]['header']['jwk']))->thumbprint('sha256'),
            (new JWK($proofs[1]['header']['jwk']))->thumbprint('sha256'),
        );
        $this->assertNotSame($proofs[0]['payload']['jti'], $proofs[1]['payload']['jti']);
        $this->assertSame(
            $this->accessTokenHash('verified-access-token'),
            $proofs[0]['payload']['ath'],
        );
        $this->assertSame($proofs[0]['payload']['ath'], $proofs[1]['payload']['ath']);
    }

    public function test_verified_userinfo_refreshes_cached_jwks_once_for_a_rotated_signing_key(): void
    {
        $claims = $this->validUserInfoClaims();
        $rotatedSigningKey = NestedTokenFactory::signingKey(kid: 'rotated-userinfo-signing-key');
        $compactUserInfo = NestedTokenFactory::userInfo(
            $claims,
            $this->decryptionKey,
            $rotatedSigningKey,
        );
        $jwksCalls = 0;
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetUserRequest::class => MockResponse::make($compactUserInfo),
            GetSingpassJwksRequest::class => function () use (&$jwksCalls, $rotatedSigningKey): MockResponse {
                $jwksCalls++;
                $key = $jwksCalls === 1 ? $this->singpassSigningKey : $rotatedSigningKey;

                return MockResponse::make([
                    'keys' => [$key->toPublic()->jsonSerialize()],
                ]);
            },
        ]);

        $userInfo = (new MyinfoConnector)->getVerifiedUserInfo($this->verifiedTokenSet());

        $this->assertSame($claims, $userInfo->claims());
        $this->assertSame(2, $jwksCalls);
        $mockClient->assertSentCount(2, GetSingpassJwksRequest::class);
    }

    /**
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function userInfoErrorResponses(): iterable
    {
        yield 'non-success response' => [[
            'error' => 'invalid_token',
            'error_description' => 'must remain private',
        ], 401];
        yield 'error JSON with success status' => [[
            'error' => 'invalid_dpop_proof',
            'error_description' => 'must remain private',
        ], 200];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('userInfoErrorResponses')]
    public function test_verified_userinfo_rejects_error_json_before_jose_processing(
        array $body,
        int $status,
    ): void {
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetUserRequest::class => MockResponse::make($body, $status),
            GetSingpassJwksRequest::class => MockResponse::make([]),
        ]);

        try {
            (new MyinfoConnector)->getVerifiedUserInfo($this->verifiedTokenSet());
            $this->fail('Expected invalid UserInfo HTTP response to fail.');
        } catch (InvalidUserInfoException $exception) {
            $this->assertStringNotContainsString('must remain private', $exception->getMessage());
            $this->assertStringNotContainsString('verified-access-token', $exception->getMessage());
        }

        $mockClient->assertSentCount(0, GetSingpassJwksRequest::class);
    }

    public function test_verified_userinfo_rejects_a_subject_that_differs_from_the_id_token(): void
    {
        $claims = $this->validUserInfoClaims();
        $claims['sub'] = 'different-subject';
        MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetUserRequest::class => MockResponse::make(NestedTokenFactory::userInfo(
                $claims,
                $this->decryptionKey,
                $this->singpassSigningKey,
            )),
            GetSingpassJwksRequest::class => MockResponse::make([
                'keys' => [$this->singpassSigningKey->toPublic()->jsonSerialize()],
            ]),
        ]);

        $this->expectException(InvalidUserInfoException::class);

        (new MyinfoConnector)->getVerifiedUserInfo($this->verifiedTokenSet());
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
            'dpop_signing_alg_values_supported' => ['ES256', 'ES384', 'ES512'],
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

    private function verifiedTokenSet(): VerifiedTokenSet
    {
        return new VerifiedTokenSet(
            'verified-access-token',
            ['sub' => 'verified-subject'],
            'DPoP',
            JWKFactory::createECKey('P-256', [
                'alg' => 'ES256',
                'use' => 'sig',
            ]),
        );
    }

    /**
     * @return array{
     *     person_info: array{name: array{value: string}},
     *     iss: string,
     *     iat: int,
     *     sub: string,
     *     aud: string
     * }
     */
    private function validUserInfoClaims(): array
    {
        return [
            'person_info' => ['name' => ['value' => 'VERIFIED USER']],
            'iss' => 'https://stg-id.singpass.gov.sg/fapi',
            'iat' => CarbonImmutable::now()->timestamp,
            'sub' => 'verified-subject',
            'aud' => 'test-client-id',
        ];
    }

    private function accessTokenHash(string $accessToken): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
    }

    private function decodeBase64Url(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }

    /**
     * @return array{header: array<string, mixed>, payload: array<string, mixed>}
     */
    private function decodeDpopProof(string $proof): array
    {
        [$encodedHeader, $encodedPayload] = explode('.', $proof, 3);

        return [
            'header' => json_decode($this->decodeBase64Url($encodedHeader), true, 512, JSON_THROW_ON_ERROR),
            'payload' => json_decode($this->decodeBase64Url($encodedPayload), true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
