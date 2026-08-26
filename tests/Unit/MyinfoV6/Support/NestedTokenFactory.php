<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6\Support;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA128KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA192KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA256KW;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\Serializer\CompactSerializer as JweCompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer as JwsCompactSerializer;

final class NestedTokenFactory
{
    public static function encryptionKey(
        string $algorithm = 'ECDH-ES+A128KW',
        string $kid = 'client-encryption-key',
    ): JWK {
        return JWKFactory::createECKey('P-256', [
            'alg' => $algorithm,
            'use' => 'enc',
            'kid' => $kid,
        ]);
    }

    public static function signingKey(
        string $algorithm = 'ES256',
        string $kid = 'singpass-signing-key',
    ): JWK {
        return JWKFactory::createECKey($algorithm === 'ES384' ? 'P-384' : 'P-256', [
            'alg' => $algorithm,
            'use' => 'sig',
            'kid' => $kid,
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $outerHeaders
     * @param array<string, mixed> $innerHeaders
     */
    public static function idToken(
        array $claims,
        JWK $encryptionKey,
        JWK $signingKey,
        string $keyWrappingAlgorithm = 'ECDH-ES+A128KW',
        string $contentEncryptionAlgorithm = 'A256CBC-HS512',
        string $signingAlgorithm = 'ES256',
        array $outerHeaders = [],
        array $innerHeaders = [],
    ): string {
        return self::nestedToken(
            json_encode($claims, JSON_THROW_ON_ERROR),
            $encryptionKey,
            $signingKey,
            $keyWrappingAlgorithm,
            $contentEncryptionAlgorithm,
            $signingAlgorithm,
            $outerHeaders,
            $innerHeaders,
        );
    }

    /**
     * @param array<string, mixed> $outerHeaders
     * @param array<string, mixed> $innerHeaders
     */
    public static function nestedToken(
        string $payloadJson,
        JWK $encryptionKey,
        JWK $signingKey,
        string $keyWrappingAlgorithm = 'ECDH-ES+A128KW',
        string $contentEncryptionAlgorithm = 'A256CBC-HS512',
        string $signingAlgorithm = 'ES256',
        array $outerHeaders = [],
        array $innerHeaders = [],
    ): string {
        $compactJws = self::signedRaw(
            $payloadJson,
            $signingKey,
            $signingAlgorithm,
            $innerHeaders,
        );

        return self::encryptRaw(
            $compactJws,
            $encryptionKey,
            $keyWrappingAlgorithm,
            $contentEncryptionAlgorithm,
            $outerHeaders,
        );
    }

    /**
     * @param array<string, mixed> $innerHeaders
     */
    public static function signedRaw(
        string $payloadJson,
        JWK $signingKey,
        string $signingAlgorithm = 'ES256',
        array $innerHeaders = [],
    ): string {
        $protectedJwsHeaders = self::headers([
            'alg' => $signingAlgorithm,
            'kid' => $signingKey->get('kid'),
        ], $innerHeaders);
        $jwsBuilder = new JWSBuilder(new AlgorithmManager([
            $signingAlgorithm === 'ES384' ? new ES384 : new ES256,
        ]));
        $jws = $jwsBuilder->create()
            ->withPayload($payloadJson)
            ->addSignature($signingKey, $protectedJwsHeaders)
            ->build();

        return (new JwsCompactSerializer)->serialize($jws);
    }

    /**
     * @param array<string, mixed> $outerHeaders
     */
    public static function encryptRaw(
        string $payload,
        JWK $encryptionKey,
        string $keyWrappingAlgorithm = 'ECDH-ES+A128KW',
        string $contentEncryptionAlgorithm = 'A256CBC-HS512',
        array $outerHeaders = [],
    ): string {
        $protectedJweHeaders = self::headers([
            'alg' => $keyWrappingAlgorithm,
            'enc' => $contentEncryptionAlgorithm,
            'kid' => $encryptionKey->get('kid'),
        ], $outerHeaders);
        $jweBuilder = new JWEBuilder(new AlgorithmManager([
            new ECDHESA128KW,
            new ECDHESA192KW,
            new ECDHESA256KW,
            $contentEncryptionAlgorithm === 'A256GCM' ? new A256GCM : new A256CBCHS512,
        ]));
        $jwe = $jweBuilder->create()
            ->withPayload($payload)
            ->withSharedProtectedHeader($protectedJweHeaders)
            ->addRecipient($encryptionKey->toPublic())
            ->build();

        return (new JweCompactSerializer)->serialize($jwe);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function rewriteProtectedHeader(string $compactToken, array $overrides): string
    {
        $parts = explode('.', $compactToken);
        $headers = json_decode(self::base64UrlDecode($parts[0]), true, 512, JSON_THROW_ON_ERROR);
        $parts[0] = self::base64UrlEncode(json_encode(
            self::headers($headers, $overrides),
            JSON_THROW_ON_ERROR,
        ));

        return implode('.', $parts);
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function headers(array $defaults, array $overrides): array
    {
        foreach ($overrides as $name => $value) {
            if ($value === null) {
                unset($defaults[$name]);
            } else {
                $defaults[$name] = $value;
            }
        }

        return $defaults;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
