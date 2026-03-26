<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\PushedAuthorizationRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class PushedAuthorizationRequestTest extends TestCase
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
        config()->set('laravel-myinfo-sg-v6.redirect_uri', 'https://example.com/default-callback');
        config()->set('laravel-myinfo-sg-v6.scopes', 'openid profile');
        config()->set('laravel-myinfo-sg-v6.chosen_jwks_sig_kid', 'client-assertion-sig');
        config()->set('laravel-myinfo-sg-v6.private_jwks', json_encode([
            'keys' => [$this->clientAssertionSigningJwk->jsonSerialize()],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_pushed_authorization_request_body_contains_expected_client_assertion_claims(): void
    {
        $request = new PushedAuthorizationRequest(
            'https://stg-id.singpass.gov.sg/fapi/par',
            'https://stg-id.singpass.gov.sg',
            $this->dpopPrivateJwk,
            $this->dpopPublicJwk,
            'test-state',
            'test-nonce',
            'test-code-challenge',
            'https://example.com/overridden-callback'
        );

        $body = $request->defaultBody();

        $this->assertSame('code', $body['response_type']);
        $this->assertSame('test-client-id', $body['client_id']);
        $this->assertSame('https://example.com/overridden-callback', $body['redirect_uri']);
        $this->assertSame('openid profile', $body['scope']);
        $this->assertSame('test-state', $body['state']);
        $this->assertSame('test-nonce', $body['nonce']);
        $this->assertSame('test-code-challenge', $body['code_challenge']);
        $this->assertSame('S256', $body['code_challenge_method']);
        $this->assertArrayHasKey('client_assertion', $body);

        [$clientAssertionHeader, $clientAssertionPayload] = $this->decodeCompactJwt($body['client_assertion']);

        $this->assertSame('ES256', $clientAssertionHeader['alg']);
        $this->assertSame('client-assertion-sig', $clientAssertionHeader['kid']);
        $this->assertSame('test-client-id', $clientAssertionPayload['iss']);
        $this->assertSame('test-client-id', $clientAssertionPayload['sub']);
        $this->assertSame('https://stg-id.singpass.gov.sg', $clientAssertionPayload['aud']);
        $this->assertArrayHasKey('jti', $clientAssertionPayload);
        $this->assertIsInt($clientAssertionPayload['iat']);
        $this->assertIsInt($clientAssertionPayload['exp']);
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
