<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use PHPUnit\Framework\Attributes\DataProvider;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5\InvalidIdTokenException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5\SigningKeyRefreshRequiredException;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\NestedJwtDecoder;
use Ziming\LaravelMyinfoSg\Tests\TestCase;
use Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5\Support\NestedTokenFactory;

class NestedJwtDecoderTest extends TestCase
{
    private JWK $signingKey;

    public function setUp(): void
    {
        parent::setUp();

        $this->signingKey = NestedTokenFactory::signingKey();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keyWrappingAlgorithms(): iterable
    {
        yield '128-bit key wrapping' => ['ECDH-ES+A128KW'];
        yield '192-bit key wrapping' => ['ECDH-ES+A192KW'];
        yield '256-bit key wrapping' => ['ECDH-ES+A256KW'];
    }

    #[DataProvider('keyWrappingAlgorithms')]
    public function test_decodes_each_allowed_id_token_key_wrapping_algorithm(string $algorithm): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey($algorithm);
        $token = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $decryptionKey,
            $this->signingKey,
            $algorithm,
        );

        $claims = $this->decode($token, $decryptionKey);

        $this->assertSame('S1234567A', $claims['sub']);
    }

    public function test_rejects_userinfo_content_encryption_in_id_token_context(): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $token = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $decryptionKey,
            $this->signingKey,
            contentEncryptionAlgorithm: 'A256GCM',
        );

        $this->expectException(InvalidIdTokenException::class);

        $this->decode($token, $decryptionKey);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredOuterHeaders(): iterable
    {
        yield 'algorithm' => ['alg'];
        yield 'content encryption' => ['enc'];
        yield 'key ID' => ['kid'];
    }

    #[DataProvider('requiredOuterHeaders')]
    public function test_requires_every_outer_protected_header(string $header): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $validToken = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $decryptionKey,
            $this->signingKey,
        );
        $token = NestedTokenFactory::rewriteProtectedHeader($validToken, [$header => null]);

        $this->expectException(InvalidIdTokenException::class);

        $this->decode($token, $decryptionKey);
    }

    public function test_rejects_a_non_es256_inner_signature_algorithm(): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $es384Key = NestedTokenFactory::signingKey('ES384');
        $token = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $decryptionKey,
            $es384Key,
            signingAlgorithm: 'ES384',
        );

        $this->expectException(InvalidIdTokenException::class);

        (new NestedJwtDecoder)->decode(
            NestedJwtDecoder::ID_TOKEN,
            $token,
            new JWKSet([$decryptionKey]),
            new JWKSet([$es384Key->toPublic()]),
        );
    }

    public function test_requires_the_inner_protected_algorithm_header(): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $validJws = NestedTokenFactory::signedRaw('{"sub":"S1234567A"}', $this->signingKey);
        $jwsWithoutAlgorithm = NestedTokenFactory::rewriteProtectedHeader($validJws, ['alg' => null]);
        $token = NestedTokenFactory::encryptRaw($jwsWithoutAlgorithm, $decryptionKey);

        $this->expectException(InvalidIdTokenException::class);

        $this->decode($token, $decryptionKey);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOuterKeyHeaders(): iterable
    {
        yield 'missing kid' => [['kid' => null]];
        yield 'unknown kid' => [['kid' => 'unknown-encryption-key']];
    }

    /**
     * @param array<string, mixed> $headers
     */
    #[DataProvider('invalidOuterKeyHeaders')]
    public function test_rejects_missing_or_unknown_outer_key_id(array $headers): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $token = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $decryptionKey,
            $this->signingKey,
            outerHeaders: $headers,
        );

        $this->expectException(InvalidIdTokenException::class);

        $this->decode($token, $decryptionKey);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidInnerKeyHeaders(): iterable
    {
        yield 'missing kid' => [['kid' => null]];
    }

    public function test_unknown_inner_signing_key_requests_a_jwks_refresh(): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $token = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $decryptionKey,
            $this->signingKey,
            innerHeaders: ['kid' => 'unknown-signing-key'],
        );

        $this->expectException(SigningKeyRefreshRequiredException::class);

        $this->decode($token, $decryptionKey);
    }

    /**
     * @param array<string, mixed> $headers
     */
    #[DataProvider('invalidInnerKeyHeaders')]
    public function test_rejects_a_missing_inner_key_id(array $headers): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $token = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $decryptionKey,
            $this->signingKey,
            innerHeaders: $headers,
        );

        $this->expectException(InvalidIdTokenException::class);

        $this->decode($token, $decryptionKey);
    }

    public function test_a_signature_made_by_a_different_key_requests_a_jwks_refresh(): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $attackerKey = NestedTokenFactory::signingKey(kid: 'attacker-key');
        $token = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $decryptionKey,
            $attackerKey,
            innerHeaders: ['kid' => $this->signingKey->get('kid')],
        );

        $this->expectException(SigningKeyRefreshRequiredException::class);

        $this->decode($token, $decryptionKey);
    }

    public function test_rejects_a_different_private_decryption_key(): void
    {
        $encryptionKey = NestedTokenFactory::encryptionKey();
        $wrongDecryptionKey = NestedTokenFactory::encryptionKey(kid: $encryptionKey->get('kid'));
        $token = NestedTokenFactory::idToken(
            ['sub' => 'S1234567A'],
            $encryptionKey,
            $this->signingKey,
        );

        $this->expectException(InvalidIdTokenException::class);

        $this->decode($token, $wrongDecryptionKey);
    }

    public function test_rejects_malformed_outer_and_inner_compact_values(): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();

        foreach ([
            'not.a.compact.token',
            NestedTokenFactory::encryptRaw('not.a.compact.jws.value', $decryptionKey),
        ] as $token) {
            try {
                $this->decode($token, $decryptionKey);
                $this->fail('Expected malformed compact token to fail.');
            } catch (InvalidIdTokenException $exception) {
                $this->assertSame('The ID token could not be decrypted and verified.', $exception->getMessage());
            }
        }
    }

    public function test_rejects_a_non_object_json_payload(): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey();
        $token = NestedTokenFactory::nestedToken('"scalar"', $decryptionKey, $this->signingKey);

        $this->expectException(InvalidIdTokenException::class);

        $this->decode($token, $decryptionKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token, JWK $decryptionKey): array
    {
        return (new NestedJwtDecoder)->decode(
            NestedJwtDecoder::ID_TOKEN,
            $token,
            new JWKSet([$decryptionKey]),
            new JWKSet([$this->signingKey->toPublic()]),
        );
    }
}
