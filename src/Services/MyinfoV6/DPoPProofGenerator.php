<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use Carbon\CarbonImmutable;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Illuminate\Support\Str;

class DPoPProofGenerator
{
    /**
     * Generate a DPoP (Demonstration of Proof-of-Possession) JWT proof.
     *
     * @param string $htm HTTP method (e.g., "POST", "GET")
     * @param string $htu HTTP URI (target endpoint URL, no query string or fragment)
     * @param JWK $privateSigningJwk Private EC key for signing
     * @param JWK $publicSigningJwk Public EC key to include in header
     * @param string|null $accessToken Optional access token for computing ath claim
     * @return string Compact serialized DPoP JWT
     * @throws \JsonException
     */
    public static function make(
        string $htm,
        string $htu,
        JWK $privateSigningJwk,
        JWK $publicSigningJwk,
        ?string $accessToken = null
    ): string {
        $algorithmManager = new AlgorithmManager([new ES256]);
        $jwsBuilder = new JWSBuilder($algorithmManager);
        $now = CarbonImmutable::now();

        $payload = [
            'htm' => $htm,
            'htu' => $htu,
            'iat' => $now->timestamp,
            'exp' => $now->addMinutes(2)->timestamp,
            'jti' => (string) Str::uuid(),
        ];

        if ($accessToken !== null) {
            $ath = rtrim(
                strtr(
                    base64_encode(hash('sha256', $accessToken, true)),
                    '+/',
                    '-_'
                ),
                '='
            );
            $payload['ath'] = $ath;
        }

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $jws = $jwsBuilder->create()
            ->withPayload($payloadJson)
            ->addSignature($privateSigningJwk, [
                'typ' => 'dpop+jwt',
                'alg' => 'ES256',
                'jwk' => $publicSigningJwk->toPublic(),
            ])
            ->build();

        $compactSerializer = new CompactSerializer;

        return $compactSerializer->serialize($jws);
    }
}
