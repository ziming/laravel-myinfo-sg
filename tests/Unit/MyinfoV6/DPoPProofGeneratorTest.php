<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use InvalidArgumentException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\DPoPProofGenerator;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class DPoPProofGeneratorTest extends TestCase
{
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
    public function test_generates_a_cryptographically_valid_proof_for_every_profile(
        string $algorithm,
        string $curve,
    ): void {
        $privateJwk = $this->privateKey($algorithm, $curve);
        $proof = DPoPProofGenerator::make(
            'POST',
            'https://stg-id.singpass.gov.sg/fapi/par',
            $privateJwk,
        );

        $jws = (new CompactSerializer)->unserialize($proof);
        $header = $jws->getSignature(0)->getProtectedHeader();
        $payload = json_decode((string) $jws->getPayload(), true, 512, JSON_THROW_ON_ERROR);
        $publicJwk = new JWK($header['jwk']);

        $this->assertSame('dpop+jwt', $header['typ']);
        $this->assertSame($algorithm, $header['alg']);
        $this->assertSame($algorithm, $header['jwk']['alg']);
        $this->assertSame($curve, $header['jwk']['crv']);
        $this->assertSame($privateJwk->get('x'), $header['jwk']['x']);
        $this->assertSame($privateJwk->get('y'), $header['jwk']['y']);
        $this->assertArrayNotHasKey('d', $header['jwk']);

        $this->assertSame('POST', $payload['htm']);
        $this->assertSame('https://stg-id.singpass.gov.sg/fapi/par', $payload['htu']);
        $this->assertIsInt($payload['iat']);
        $this->assertIsInt($payload['exp']);
        $this->assertSame(120, $payload['exp'] - $payload['iat']);
        $this->assertIsString($payload['jti']);
        $this->assertNotSame('', $payload['jti']);
        $this->assertArrayNotHasKey('ath', $payload);
        $this->assertTrue(
            (new JWSVerifier(new AlgorithmManager([$this->joseAlgorithm($algorithm)])))
                ->verifyWithKey($jws, $publicJwk, 0),
        );
    }

    #[DataProvider('dpopProfiles')]
    public function test_access_token_hash_is_present_only_when_requested(
        string $algorithm,
        string $curve,
    ): void {
        $accessToken = 'example-access-token';
        $proof = DPoPProofGenerator::make(
            'GET',
            'https://stg-id.singpass.gov.sg/fapi/userinfo',
            $this->privateKey($algorithm, $curve),
            accessToken: $accessToken,
        );

        $jws = (new CompactSerializer)->unserialize($proof);
        $payload = json_decode((string) $jws->getPayload(), true, 512, JSON_THROW_ON_ERROR);
        $expectedAth = rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');

        $this->assertSame($expectedAth, $payload['ath']);
        $this->assertStringNotContainsString('=', $payload['ath']);
    }

    public function test_matching_deprecated_public_key_parameter_remains_compatible(): void
    {
        $privateJwk = $this->privateKey('ES256', 'P-256');

        $proof = DPoPProofGenerator::make(
            'POST',
            'https://stg-id.singpass.gov.sg/fapi/token',
            $privateJwk,
            $privateJwk->toPublic(),
        );

        $this->assertNotSame('', $proof);
    }

    public function test_rejects_a_public_key_that_does_not_match_the_private_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match');

        DPoPProofGenerator::make(
            'POST',
            'https://stg-id.singpass.gov.sg/fapi/token',
            $this->privateKey('ES256', 'P-256'),
            $this->privateKey('ES256', 'P-256')->toPublic(),
        );
    }

    public function test_rejects_a_missing_private_parameter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('private signing key is invalid');

        DPoPProofGenerator::make(
            'POST',
            'https://stg-id.singpass.gov.sg/fapi/token',
            $this->privateKey('ES256', 'P-256')->toPublic(),
        );
    }

    public function test_rejects_an_unsupported_algorithm(): void
    {
        $key = new JWK([
            ...$this->privateKey('ES256', 'P-256')->all(),
            'alg' => 'RS256',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('algorithm is not supported');

        DPoPProofGenerator::make('POST', 'https://example.com/token', $key);
    }

    public function test_rejects_a_wrong_algorithm_curve_mapping(): void
    {
        $key = new JWK([
            ...$this->privateKey('ES384', 'P-384')->all(),
            'alg' => 'ES256',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('private signing key is invalid');

        DPoPProofGenerator::make('POST', 'https://example.com/token', $key);
    }

    public function test_rejects_algorithm_substitution_on_an_existing_key(): void
    {
        $key = new JWK([
            ...$this->privateKey('ES256', 'P-256')->all(),
            'alg' => 'ES512',
        ]);

        $this->expectException(InvalidArgumentException::class);

        DPoPProofGenerator::make('POST', 'https://example.com/token', $key);
    }

    private function privateKey(string $algorithm, string $curve): JWK
    {
        return JWKFactory::createECKey($curve, [
            'alg' => $algorithm,
            'use' => 'sig',
        ]);
    }

    private function joseAlgorithm(string $algorithm): ES256|ES384|ES512
    {
        return match ($algorithm) {
            'ES256' => new ES256,
            'ES384' => new ES384,
            'ES512' => new ES512,
        };
    }
}
