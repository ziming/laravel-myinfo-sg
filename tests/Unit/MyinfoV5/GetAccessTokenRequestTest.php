<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GetAccessTokenRequestTest extends TestCase
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
        config()->set('laravel-myinfo-sg-v5.chosen_jwks_sig_kid', 'client-assertion-sig');
        config()->set('laravel-myinfo-sg-v5.private_jwks', json_encode([
            'keys' => [$this->clientAssertionSigningJwk->jsonSerialize()],
        ], JSON_THROW_ON_ERROR));

    }

    public function test_access_token_request_body_uses_the_explicit_transaction_values(): void
    {
        $request = new GetAccessTokenRequest(
            'https://stg-id.singpass.gov.sg/fapi/token',
            'test-auth-code',
            'https://stg-id.singpass.gov.sg',
            'https://example.com/overridden-callback',
            'explicit-code-verifier',
            $this->dpopPrivateJwk,
        );

        $body = $request->defaultBody();

        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('test-auth-code', $body['code']);
        $this->assertSame('https://example.com/overridden-callback', $body['redirect_uri']);
        $this->assertSame('test-client-id', $body['client_id']);
        $this->assertSame('explicit-code-verifier', $body['code_verifier']);
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
            'explicit-code-verifier',
            $this->dpopPrivateJwk,
        );

        $headers = $request->defaultHeaders();

        $this->assertArrayHasKey('DPoP', $headers);

        $pendingRequest = $request->createPendingRequest();

        $this->assertSame(
            'application/x-www-form-urlencoded',
            $pendingRequest->headers()->get('Content-Type')
        );
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
    public function test_token_proofs_use_each_profile_with_fresh_jtis(string $algorithm, string $curve): void
    {
        $privateJwk = JWKFactory::createECKey($curve, [
            'alg' => $algorithm,
            'use' => 'sig',
        ]);
        $request = new GetAccessTokenRequest(
            'https://stg-id.singpass.gov.sg/fapi/token',
            'test-auth-code',
            'https://stg-id.singpass.gov.sg/fapi',
            'https://example.com/callback',
            'explicit-code-verifier',
            $privateJwk,
        );

        [$firstHeader, $firstPayload] = $this->decodeCompactJwt($request->defaultHeaders()['DPoP']);
        [$secondHeader, $secondPayload] = $this->decodeCompactJwt($request->defaultHeaders()['DPoP']);

        $this->assertSame($algorithm, $firstHeader['alg']);
        $this->assertSame($curve, $firstHeader['jwk']['crv']);
        $this->assertSame($firstHeader['jwk'], $secondHeader['jwk']);
        $this->assertArrayNotHasKey('d', $firstHeader['jwk']);
        $this->assertSame('POST', $firstPayload['htm']);
        $this->assertSame('https://stg-id.singpass.gov.sg/fapi/token', $firstPayload['htu']);
        $this->assertArrayNotHasKey('ath', $firstPayload);
        $this->assertNotSame($firstPayload['jti'], $secondPayload['jti']);
    }

    public function test_access_token_request_uses_the_configured_signing_kid_and_algorithm(): void
    {
        foreach ([
            'ES256' => 'P-256',
            'ES384' => 'P-384',
            'ES512' => 'P-521',
        ] as $algorithm => $curve) {
            $kid = 'sig-'.strtolower($algorithm);
            $signingJwk = JWKFactory::createECKey($curve, [
                'alg' => $algorithm,
                'use' => 'sig',
                'kid' => $kid,
            ]);

            config()->set('laravel-myinfo-sg-v5.chosen_jwks_sig_kid', $kid);
            config()->set('laravel-myinfo-sg-v5.private_jwks', json_encode([
                'keys' => [$signingJwk->jsonSerialize()],
            ], JSON_THROW_ON_ERROR));

            $request = new GetAccessTokenRequest(
                'https://stg-id.singpass.gov.sg/fapi/token',
                'test-auth-code',
                'https://stg-id.singpass.gov.sg',
                'https://example.com/overridden-callback',
                'explicit-code-verifier',
                $this->dpopPrivateJwk,
            );

            $body = $request->defaultBody();
            [$clientAssertionHeader] = $this->decodeCompactJwt($body['client_assertion']);

            $this->assertSame($algorithm, $clientAssertionHeader['alg']);
            $this->assertSame($kid, $clientAssertionHeader['kid']);
        }
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
