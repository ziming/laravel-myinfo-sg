<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetUserRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GetUserRequestTest extends TestCase
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
    public function test_builds_a_subject_bound_userinfo_request_with_ath(
        string $algorithm,
        string $curve,
    ): void {
        $endpoint = 'https://stg-id.singpass.gov.sg/fapi/userinfo';
        $accessToken = 'test-access-token';
        $privateDpopKey = JWKFactory::createECKey($curve, [
            'alg' => $algorithm,
            'use' => 'sig',
        ]);
        $publicDpopKey = $privateDpopKey->toPublic();
        $request = new GetUserRequest(
            $endpoint,
            $accessToken,
            $privateDpopKey,
            $publicDpopKey,
        );

        $headers = $request->defaultHeaders();
        $proof = $headers['DPoP'];

        $this->assertSame('DPoP '.$accessToken, $headers['Authorization']);
        $this->assertIsString($proof);

        $jws = (new CompactSerializer)->unserialize($proof);
        $protectedHeaders = $jws->getSignature(0)->getProtectedHeader();
        $payload = json_decode((string) $jws->getPayload(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('dpop+jwt', $protectedHeaders['typ']);
        $this->assertSame($algorithm, $protectedHeaders['alg']);
        $this->assertIsArray($protectedHeaders['jwk']);
        $this->assertSame($publicDpopKey->get('x'), $protectedHeaders['jwk']['x']);
        $this->assertSame($publicDpopKey->get('y'), $protectedHeaders['jwk']['y']);
        $this->assertArrayNotHasKey('d', $protectedHeaders['jwk']);
        $this->assertSame('GET', $payload['htm']);
        $this->assertSame($endpoint, $payload['htu']);
        $this->assertSame($this->accessTokenHash($accessToken), $payload['ath']);
        $this->assertTrue(
            (new JWSVerifier(new AlgorithmManager([$this->joseAlgorithm($algorithm)])))->verifyWithKey(
                $jws,
                $publicDpopKey,
                0,
            ),
        );

        [, $secondPayload] = $this->decodeCompactJwt($request->defaultHeaders()['DPoP']);
        $this->assertNotSame($payload['jti'], $secondPayload['jti']);
    }

    public function test_precomputed_proof_compatibility_path_is_never_retried(): void
    {
        $request = new GetUserRequest(
            'https://stg-id.singpass.gov.sg/fapi/userinfo',
            'test-access-token',
            'precomputed-proof',
        );

        $this->assertSame(1, $request->tries);
        $this->assertSame('precomputed-proof', $request->defaultHeaders()['DPoP']);
    }

    private function accessTokenHash(string $accessToken): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
    }

    private function joseAlgorithm(string $algorithm): ES256|ES384|ES512
    {
        return match ($algorithm) {
            'ES256' => new ES256,
            'ES384' => new ES384,
            'ES512' => new ES512,
        };
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>}
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
