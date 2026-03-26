<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;

class ClientAssertionSigningKeyResolver
{
    /**
     * @return array{jwk: JWK, alg: string, algorithm_manager: AlgorithmManager}
     */
    public static function resolve(): array
    {
        $privateJwks = JWKFactory::createFromJsonObject(
            config('laravel-myinfo-sg-v6.private_jwks')
        );

        if (! $privateJwks instanceof JWKSet) {
            throw new \RuntimeException('Expected a JWKS for v6 private_jwks');
        }

        $signingJwk = self::resolveSigningJwk($privateJwks);

        if (! $signingJwk->has('kid')) {
            throw new \RuntimeException('Signing key must declare kid');
        }

        $alg = self::resolveAlgorithm($signingJwk);

        return [
            'jwk' => $signingJwk,
            'alg' => $alg,
            'algorithm_manager' => self::buildAlgorithmManager($alg),
        ];
    }

    private static function resolveSigningJwk(JWKSet $privateJwks): JWK
    {
        $chosenKid = config('laravel-myinfo-sg-v6.chosen_jwks_sig_kid');

        if (is_string($chosenKid) && $chosenKid !== '') {
            if (! $privateJwks->has($chosenKid)) {
                throw new \RuntimeException("Configured signing key [{$chosenKid}] was not found");
            }

            $signingJwk = $privateJwks->get($chosenKid);

            if (! self::isSigningKey($signingJwk)) {
                throw new \RuntimeException("Configured key [{$chosenKid}] is not a signing key");
            }

            return $signingJwk;
        }

        $signingKeys = array_values(array_filter(
            $privateJwks->all(),
            static fn (JWK $jwk): bool => self::isSigningKey($jwk)
        ));

        return match (count($signingKeys)) {
            0 => throw new \RuntimeException('No signing key found in private JWKS'),
            1 => $signingKeys[0],
            default => throw new \RuntimeException(
                'Multiple signing keys found; configure MYINFO_V6_CHOSEN_JWKS_SIG_KID'
            ),
        };
    }

    private static function isSigningKey(JWK $jwk): bool
    {
        return $jwk->has('use') && $jwk->get('use') === 'sig';
    }

    private static function resolveAlgorithm(JWK $jwk): string
    {
        if (! $jwk->has('alg')) {
            throw new \RuntimeException('Signing key must declare alg');
        }

        $alg = $jwk->get('alg');

        if (! is_string($alg) || $alg === '') {
            throw new \RuntimeException('Signing key alg must be a non-empty string');
        }

        return $alg;
    }

    private static function buildAlgorithmManager(string $alg): AlgorithmManager
    {
        return match ($alg) {
            'ES256' => new AlgorithmManager([new ES256]),
            'ES384' => new AlgorithmManager([new ES384]),
            'ES512' => new AlgorithmManager([new ES512]),
            default => throw new \RuntimeException("Unsupported client assertion alg [{$alg}]"),
        };
    }
}
