<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use InvalidArgumentException;

final class SingpassAlgorithmProfile
{
    private const array CLIENT_ASSERTION_SIGNING_ALGORITHMS = [
        'ES256' => 'P-256',
        'ES384' => 'P-384',
        'ES512' => 'P-521',
    ];

    private const array DPOP_SIGNING_ALGORITHMS = self::CLIENT_ASSERTION_SIGNING_ALGORITHMS;

    private const array JWE_KEY_WRAPPING_ALGORITHMS = [
        'ECDH-ES+A128KW',
        'ECDH-ES+A192KW',
        'ECDH-ES+A256KW',
    ];

    private const array ENCRYPTION_KEY_CURVES = [
        'P-256',
        'P-384',
        'P-521',
    ];

    private const array ID_TOKEN_SIGNING_ALGORITHMS = ['ES256'];

    private const array ID_TOKEN_CONTENT_ENCRYPTION_ALGORITHMS = ['A256CBC-HS512'];

    private const array USERINFO_SIGNING_ALGORITHMS = ['ES256'];

    private const array USERINFO_CONTENT_ENCRYPTION_ALGORITHMS = ['A256GCM'];

    /**
     * @return array<string, string>
     */
    public static function clientAssertionSigningAlgorithms(): array
    {
        return self::CLIENT_ASSERTION_SIGNING_ALGORITHMS;
    }

    public static function clientAssertionCurve(string $algorithm): string
    {
        return self::CLIENT_ASSERTION_SIGNING_ALGORITHMS[$algorithm]
            ?? throw new InvalidArgumentException("Unsupported client assertion signing algorithm [{$algorithm}].");
    }

    /**
     * @return array<string, string>
     */
    public static function dpopSigningAlgorithms(): array
    {
        return self::DPOP_SIGNING_ALGORITHMS;
    }

    public static function dpopCurve(string $algorithm): string
    {
        return self::DPOP_SIGNING_ALGORITHMS[$algorithm]
            ?? throw new InvalidArgumentException("Unsupported DPoP signing algorithm [{$algorithm}].");
    }

    /**
     * @return list<string>
     */
    public static function jweKeyWrappingAlgorithms(): array
    {
        return self::JWE_KEY_WRAPPING_ALGORITHMS;
    }

    /**
     * @return list<string>
     */
    public static function encryptionKeyCurves(): array
    {
        return self::ENCRYPTION_KEY_CURVES;
    }

    /**
     * @return list<string>
     */
    public static function idTokenSigningAlgorithms(): array
    {
        return self::ID_TOKEN_SIGNING_ALGORITHMS;
    }

    /**
     * @return list<string>
     */
    public static function idTokenContentEncryptionAlgorithms(): array
    {
        return self::ID_TOKEN_CONTENT_ENCRYPTION_ALGORITHMS;
    }

    /**
     * @return list<string>
     */
    public static function userInfoSigningAlgorithms(): array
    {
        return self::USERINFO_SIGNING_ALGORITHMS;
    }

    /**
     * @return list<string>
     */
    public static function userInfoContentEncryptionAlgorithms(): array
    {
        return self::USERINFO_CONTENT_ENCRYPTION_ALGORITHMS;
    }
}
