<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\PushedAuthorizationRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class PushedAuthorizationRequestTest extends TestCase
{
    private JWK $clientAssertionSigningJwk;
    private JWK $dpopPrivateJwk;
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

        config()->set('laravel-myinfo-sg-v5.client_id', 'test-client-id');
        config()->set('laravel-myinfo-sg-v5.redirect_uri', 'https://example.com/default-callback');
        config()->set('laravel-myinfo-sg-v5.scopes', 'openid profile');
        config()->set('laravel-myinfo-sg-v5.chosen_jwks_sig_kid', 'client-assertion-sig');
        config()->set('laravel-myinfo-sg-v5.private_jwks', json_encode([
            'keys' => [$this->clientAssertionSigningJwk->jsonSerialize()],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_pushed_authorization_request_body_contains_expected_client_assertion_claims(): void
    {
        $request = new PushedAuthorizationRequest(
            'https://stg-id.singpass.gov.sg/fapi/par',
            'https://stg-id.singpass.gov.sg',
            $this->dpopPrivateJwk,
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
     * @return iterable<string, array{string, string}>
     */
    public static function dpopProfiles(): iterable
    {
        yield 'ES256 / P-256' => ['ES256', 'P-256'];
        yield 'ES384 / P-384' => ['ES384', 'P-384'];
        yield 'ES512 / P-521' => ['ES512', 'P-521'];
    }

    #[DataProvider('dpopProfiles')]
    public function test_par_proofs_use_each_profile_with_fresh_jtis(string $algorithm, string $curve): void
    {
        $privateJwk = JWKFactory::createECKey($curve, [
            'alg' => $algorithm,
            'use' => 'sig',
        ]);
        $request = new PushedAuthorizationRequest(
            'https://stg-id.singpass.gov.sg/fapi/par',
            'https://stg-id.singpass.gov.sg/fapi',
            $privateJwk,
            'test-state',
            'test-nonce',
            'test-code-challenge',
        );

        [$firstHeader, $firstPayload] = $this->decodeCompactJwt($request->defaultHeaders()['DPoP']);
        [$secondHeader, $secondPayload] = $this->decodeCompactJwt($request->defaultHeaders()['DPoP']);

        $this->assertSame($algorithm, $firstHeader['alg']);
        $this->assertSame($curve, $firstHeader['jwk']['crv']);
        $this->assertSame($firstHeader['jwk'], $secondHeader['jwk']);
        $this->assertArrayNotHasKey('d', $firstHeader['jwk']);
        $this->assertSame('POST', $firstPayload['htm']);
        $this->assertSame('https://stg-id.singpass.gov.sg/fapi/par', $firstPayload['htu']);
        $this->assertArrayNotHasKey('ath', $firstPayload);
        $this->assertNotSame($firstPayload['jti'], $secondPayload['jti']);
    }

    public function test_pushed_authorization_request_rejects_a_mismatched_signing_curve(): void
    {
        $mismatchedKey = new JWK([
            ...JWKFactory::createECKey('P-256', [
                'use' => 'sig',
                'kid' => 'mismatched-signing-key',
            ])->all(),
            'alg' => 'ES384',
        ]);

        config()->set('laravel-myinfo-sg-v5.chosen_jwks_sig_kid', 'mismatched-signing-key');
        config()->set('laravel-myinfo-sg-v5.private_jwks', json_encode([
            'keys' => [$mismatchedKey->jsonSerialize()],
        ], JSON_THROW_ON_ERROR));

        $request = new PushedAuthorizationRequest(
            'https://stg-id.singpass.gov.sg/fapi/par',
            'https://stg-id.singpass.gov.sg',
            $this->dpopPrivateJwk,
            'test-state',
            'test-nonce',
            'test-code-challenge',
            'https://example.com/overridden-callback'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[crv]');
        $this->expectExceptionMessage('[mismatched-signing-key]');

        $request->defaultBody();
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
