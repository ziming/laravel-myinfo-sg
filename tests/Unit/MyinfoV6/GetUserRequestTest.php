<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetUserRequest;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GetUserRequestTest extends TestCase
{
    public function test_builds_a_subject_bound_userinfo_request_with_ath(): void
    {
        $endpoint = 'https://stg-id.singpass.gov.sg/fapi/userinfo';
        $accessToken = 'test-access-token';
        $privateDpopKey = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
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
        $this->assertSame('ES256', $protectedHeaders['alg']);
        $this->assertIsArray($protectedHeaders['jwk']);
        $this->assertSame($publicDpopKey->get('x'), $protectedHeaders['jwk']['x']);
        $this->assertSame($publicDpopKey->get('y'), $protectedHeaders['jwk']['y']);
        $this->assertArrayNotHasKey('d', $protectedHeaders['jwk']);
        $this->assertSame('GET', $payload['htm']);
        $this->assertSame($endpoint, $payload['htu']);
        $this->assertSame($this->accessTokenHash($accessToken), $payload['ath']);
        $this->assertTrue(
            (new JWSVerifier(new AlgorithmManager([new ES256])))->verifyWithKey(
                $jws,
                $publicDpopKey,
                0,
            ),
        );
    }

    private function accessTokenHash(string $accessToken): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
    }
}
