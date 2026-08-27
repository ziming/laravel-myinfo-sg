<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use InvalidArgumentException;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;

final class JwkSetGenerator
{
    public const string DEFAULT_SIGNING_ALGORITHM = 'ES256';

    public const string DEFAULT_ENCRYPTION_ALGORITHM = 'ECDH-ES+A128KW';

    public const string DEFAULT_ENCRYPTION_CURVE = 'P-256';

    /**
     * @return array<string, mixed>
     */
    public function signingKey(string $algorithm = self::DEFAULT_SIGNING_ALGORITHM): array
    {
        $key = JWKFactory::createECKey(SingpassAlgorithmProfile::clientAssertionCurve($algorithm), [
            'alg' => $algorithm,
            'use' => 'sig',
        ]);

        return $this->withKeyId($key, 'sig')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function encryptionKey(
        string $algorithm = self::DEFAULT_ENCRYPTION_ALGORITHM,
        string $curve = self::DEFAULT_ENCRYPTION_CURVE
    ): array {
        if (! in_array($algorithm, SingpassAlgorithmProfile::jweKeyWrappingAlgorithms(), true)) {
            throw new InvalidArgumentException("Unsupported encryption algorithm [{$algorithm}].");
        }

        if (! in_array($curve, SingpassAlgorithmProfile::encryptionKeyCurves(), true)) {
            throw new InvalidArgumentException("Unsupported encryption curve [{$curve}].");
        }

        $key = JWKFactory::createECKey($curve, [
            'alg' => $algorithm,
            'use' => 'enc',
        ]);

        return $this->withKeyId($key, 'enc')->all();
    }

    /**
     * @param array<string, mixed> $privateKey
     * @return array<string, mixed>
     */
    public function publicKey(array $privateKey): array
    {
        return (new JWK($privateKey))->toPublic()->all();
    }

    private function withKeyId(JWK $key, string $prefix): JWK
    {
        return new JWK([
            ...$key->all(),
            'kid' => $prefix.'-'.$key->thumbprint('sha256'),
        ]);
    }
}
