<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GetAccessTokenRequestTest extends TestCase
{
    private JWK $clientAssertionSigningJwk;
    private JWK $dpopPrivateJwk;
    private JWK $dpopPublicJwk;

    public function setUp(): void
    {
        parent::setUp();

        $this->clientAssertionSigningJwk = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
            'kid' => 'client-assertion-sig',
        ]);
        $this->dpopPrivateJwk = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
        ]);
        $this->dpopPublicJwk = $this->dpopPrivateJwk->toPublic();

        config()->set('laravel-myinfo-sg-v6.client_id', 'test-client-id');
        config()->set('laravel-myinfo-sg-v6.chosen_jwks_sig_kid', 'client-assertion-sig');
        config()->set('laravel-myinfo-sg-v6.private_jwks', json_encode([
            'keys' => [$this->clientAssertionSigningJwk->jsonSerialize()],
        ], JSON_THROW_ON_ERROR));

        session()->put(config('laravel-myinfo-sg-v6.code_verifier_session_key'), 'test-code-verifier');
    }

    public function test_access_token_request_body_uses_the_passed_redirect_uri(): void
    {
        $request = new GetAccessTokenRequest(
            'https://stg-id.singpass.gov.sg/fapi/token',
            'test-auth-code',
            'https://stg-id.singpass.gov.sg',
            'https://example.com/overridden-callback',
            $this->dpopPrivateJwk,
            $this->dpopPublicJwk
        );

        $body = $request->defaultBody();

        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('test-auth-code', $body['code']);
        $this->assertSame('https://example.com/overridden-callback', $body['redirect_uri']);
        $this->assertSame('test-client-id', $body['client_id']);
        $this->assertSame('test-code-verifier', $body['code_verifier']);
        $this->assertArrayHasKey('client_assertion', $body);

        [, $clientAssertionPayload] = $this->decodeCompactJwt($body['client_assertion']);

        $this->assertSame('test-client-id', $clientAssertionPayload['iss']);
        $this->assertSame('test-client-id', $clientAssertionPayload['sub']);
        $this->assertSame('https://stg-id.singpass.gov.sg', $clientAssertionPayload['aud']);
        $this->assertSame('test-auth-code', $clientAssertionPayload['code']);
    }

    public function test_access_token_request_headers_include_dpop(): void
    {
        $request = new GetAccessTokenRequest(
            'https://stg-id.singpass.gov.sg/fapi/token',
            'test-auth-code',
            'https://stg-id.singpass.gov.sg',
            'https://example.com/overridden-callback',
            $this->dpopPrivateJwk,
            $this->dpopPublicJwk
        );

        $headers = $request->defaultHeaders();

        $this->assertArrayHasKey('DPoP', $headers);
        $this->assertSame('application/x-www-form-urlencoded', $headers['Content-Type']);
    }

    public function test_access_token_request_uses_the_configured_signing_kid_and_algorithm(): void
    {
        $es256Jwk = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
            'kid' => 'sig-es256',
        ]);
        $es384Jwk = JWKFactory::createECKey('P-384', [
            'alg' => 'ES384',
            'use' => 'sig',
            'kid' => 'sig-es384',
        ]);

        config()->set('laravel-myinfo-sg-v6.chosen_jwks_sig_kid', 'sig-es384');
        config()->set('laravel-myinfo-sg-v6.private_jwks', json_encode([
            'keys' => [
                $es256Jwk->jsonSerialize(),
                $es384Jwk->jsonSerialize(),
            ],
        ], JSON_THROW_ON_ERROR));

        $request = new GetAccessTokenRequest(
            'https://stg-id.singpass.gov.sg/fapi/token',
            'test-auth-code',
            'https://stg-id.singpass.gov.sg',
            'https://example.com/overridden-callback',
            $this->dpopPrivateJwk,
            $this->dpopPublicJwk
        );

        $body = $request->defaultBody();
        [$clientAssertionHeader] = $this->decodeCompactJwt($body['client_assertion']);

        $this->assertSame('ES384', $clientAssertionHeader['alg']);
        $this->assertSame('sig-es384', $clientAssertionHeader['kid']);
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>}
     * @throws \JsonException
     */
    private function decodeCompactJwt(string $compactJwt): array
    {
        [$encodedHeader, $encodedPayload] = explode('.', $compactJwt, 3);

        return [
            json_decode($this->decodeBase64Url($encodedHeader), true, 512, JSON_THROW_ON_ERROR),
            json_decode($this->decodeBase64Url($encodedPayload), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function decodeBase64Url(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
