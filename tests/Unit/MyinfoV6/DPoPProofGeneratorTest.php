<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\DPoPProofGenerator;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class DPoPProofGeneratorTest extends TestCase
{
    private JWK $privateJwk;
    private JWK $publicJwk;

    public function setUp(): void
    {
        parent::setUp();
        $this->privateJwk = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
        ]);
        $this->publicJwk = $this->privateJwk->toPublic();
    }

    public function test_dpop_proof_without_access_token(): void
    {
        $proof = DPoPProofGenerator::make(
            'POST',
            'https://stg-id.singpass.gov.sg/fapi/par',
            $this->privateJwk,
            $this->publicJwk
        );

        [$header, $payload] = $this->decodeCompactJwt($proof);

        $this->assertSame('dpop+jwt', $header['typ']);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame($this->publicJwk->get('x'), $header['jwk']['x']);
        $this->assertSame($this->publicJwk->get('y'), $header['jwk']['y']);
        $this->assertArrayNotHasKey('d', $header['jwk']);

        $this->assertSame('POST', $payload['htm']);
        $this->assertSame('https://stg-id.singpass.gov.sg/fapi/par', $payload['htu']);
        $this->assertIsInt($payload['iat']);
        $this->assertIsInt($payload['exp']);
        $this->assertSame(120, $payload['exp'] - $payload['iat']);
        $this->assertNotEmpty($payload['jti']);
        $this->assertArrayNotHasKey('ath', $payload);
    }

    public function test_dpop_proof_with_access_token(): void
    {
        $accessToken = 'example-access-token';
        $proof = DPoPProofGenerator::make(
            'GET',
            'https://stg-id.singpass.gov.sg/fapi/userinfo',
            $this->privateJwk,
            $this->publicJwk,
            $accessToken
        );

        [, $payload] = $this->decodeCompactJwt($proof);

        $expectedAth = rtrim(
            strtr(
                base64_encode(hash('sha256', $accessToken, true)),
                '+/',
                '-_'
            ),
            '='
        );

        $this->assertSame($expectedAth, $payload['ath']);
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
