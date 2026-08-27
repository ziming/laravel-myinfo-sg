<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Illuminate\Support\Facades\Cache;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use ReflectionMethod;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidUserInfoException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassJwksRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetUserRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses\GetUserResponse;
use Ziming\LaravelMyinfoSg\Tests\TestCase;
use Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6\Support\NestedTokenFactory;

class GetUserResponseTest extends TestCase
{
    private JWK $decryptionKey;

    private JWK $singpassSigningKey;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        config()->set('laravel-myinfo-sg-v6.issuer_uri', 'https://stg-id.singpass.gov.sg');
        config()->set('laravel-myinfo-sg-v6.client_id', 'test-client-id');

        $clientSigningKey = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
            'kid' => 'client-signing-key',
        ]);
        $this->decryptionKey = NestedTokenFactory::encryptionKey();
        $this->singpassSigningKey = NestedTokenFactory::signingKey();
        $dpopKey = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
        ]);

        config()->set('laravel-myinfo-sg-v6.private_jwks', json_encode([
            'keys' => [
                $clientSigningKey->jsonSerialize(),
                $this->decryptionKey->jsonSerialize(),
            ],
        ], JSON_THROW_ON_ERROR));
        session()->put(
            config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key'),
            json_encode($dpopKey, JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

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

    public function test_low_level_response_uses_shared_processing_without_claiming_subject_binding(): void
    {
        $claims = [
            'person_info' => ['name' => ['value' => 'COMPAT USER']],
            'iss' => 'https://stg-id.singpass.gov.sg/fapi',
            'iat' => time(),
            'sub' => 'compatibility-subject',
            'aud' => 'test-client-id',
        ];
        $compactUserInfo = NestedTokenFactory::userInfo(
            $claims,
            $this->decryptionKey,
            $this->singpassSigningKey,
        );
        MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetUserRequest::class => MockResponse::make($compactUserInfo),
            GetSingpassJwksRequest::class => MockResponse::make([
                'keys' => [$this->singpassSigningKey->toPublic()->jsonSerialize()],
            ]),
        ]);

        $response = (new MyinfoConnector)->getUser('compatibility-access-token');

        $this->assertSame($claims, $response->json());
        $this->assertSame($claims['person_info'], $response->json('person_info'));
    }

    public function test_low_level_response_rejects_http_error_json_before_jose_processing(): void
    {
        $mockClient = MockClient::global([
            GetSingpassOpenIdConfigurationRequest::class => MockResponse::make($this->metadata()),
            GetUserRequest::class => MockResponse::make([
                'error' => 'invalid_token',
                'error_description' => 'must remain private',
            ], 401),
            GetSingpassJwksRequest::class => MockResponse::make([]),
        ]);

        try {
            (new MyinfoConnector)->getUser('secret-access-token')->json();
            $this->fail('Expected an error response to fail.');
        } catch (InvalidUserInfoException $exception) {
            $this->assertStringNotContainsString('must remain private', $exception->getMessage());
            $this->assertStringNotContainsString('secret-access-token', $exception->getMessage());
        }

        $mockClient->assertSentCount(0, GetSingpassJwksRequest::class);
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
}
