<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

class DPoPProofGenerator
{
    /**
     * Generate a DPoP (Demonstration of Proof-of-Possession) JWT proof.
     *
     * @param string $htm HTTP method (e.g., "POST", "GET")
     * @param string $htu HTTP URI (target endpoint URL, no query string or fragment)
     * @param JWK $privateSigningJwk Private EC key for signing
     * @param JWK|null $publicSigningJwk Deprecated public-key compatibility parameter
     * @param string|null $accessToken Optional access token for computing ath claim
     * @return string Compact serialized DPoP JWT
     * @throws \JsonException
     */
    public static function make(
        string $htm,
        string $htu,
        JWK $privateSigningJwk,
        ?JWK $publicSigningJwk = null,
        ?string $accessToken = null,
    ): string {
        $algorithm = self::signingAlgorithm($privateSigningJwk);
        $derivedPublicJwk = $privateSigningJwk->toPublic();

        if ($publicSigningJwk !== null && $publicSigningJwk->all() != $derivedPublicJwk->all()) {
            throw new InvalidArgumentException('The DPoP public key does not match the private key.');
        }

        if ($htm === '' || strtoupper($htm) !== $htm) {
            throw new InvalidArgumentException('The DPoP HTTP method must be uppercase.');
        }

        if (
            $htu === ''
            || filter_var($htu, FILTER_VALIDATE_URL) === false
            || parse_url($htu, PHP_URL_QUERY) !== null
            || parse_url($htu, PHP_URL_FRAGMENT) !== null
        ) {
            throw new InvalidArgumentException('The DPoP target URI is invalid.');
        }

        $algorithmManager = new AlgorithmManager([match ($algorithm) {
            'ES256' => new ES256,
            'ES384' => new ES384,
            'ES512' => new ES512,
            default => throw new InvalidArgumentException('The DPoP signing algorithm is not supported.'),
        }]);
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
                'alg' => $algorithm,
                'jwk' => $derivedPublicJwk,
            ])
            ->build();

        $compactSerializer = new CompactSerializer;

        return $compactSerializer->serialize($jws);
    }

    /**
     * Validate a private DPoP signing key against the local algorithm policy.
     *
     * @internal
     */
    public static function signingAlgorithm(JWK $privateSigningJwk): string
    {
        $algorithm = self::nonEmptyString($privateSigningJwk, 'alg');
        $keyType = self::nonEmptyString($privateSigningJwk, 'kty');
        $use = self::nonEmptyString($privateSigningJwk, 'use');
        $curve = self::nonEmptyString($privateSigningJwk, 'crv');
        self::nonEmptyString($privateSigningJwk, 'x');
        self::nonEmptyString($privateSigningJwk, 'y');
        self::nonEmptyString($privateSigningJwk, 'd');

        try {
            $expectedCurve = SingpassAlgorithmProfile::dpopCurve($algorithm);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('The DPoP signing algorithm is not supported.');
        }

        if (
            $keyType !== 'EC'
            || $use !== 'sig'
            || $curve !== $expectedCurve
        ) {
            throw new InvalidArgumentException('The DPoP private signing key is invalid.');
        }

        return $algorithm;
    }

    private static function nonEmptyString(JWK $jwk, string $parameter): string
    {
        if (! $jwk->has($parameter)) {
            throw new InvalidArgumentException('The DPoP private signing key is invalid.');
        }

        $value = $jwk->get($parameter);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('The DPoP private signing key is invalid.');
        }

        return $value;
    }
}
