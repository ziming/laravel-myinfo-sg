<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use InvalidArgumentException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA128KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA192KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA256KW;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\JWELoader;
use Jose\Component\Encryption\Serializer\CompactSerializer as JweCompactSerializer;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSLoader;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer as JwsCompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Throwable;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidIdTokenException;

final class NestedJwtDecoder
{
    public const string ID_TOKEN = 'id_token';

    public const string USERINFO = 'userinfo';

    /**
     * @return array<array-key, mixed>
     */
    public function decode(
        string $context,
        #[\SensitiveParameter] string $compactToken,
        JWKSet $privateDecryptionJwks,
        JWKSet $publicSigningJwks,
    ): array {
        try {
            $profile = $this->profile($context);
            $jweSerializer = new JweCompactSerializer;
            $jwe = $jweSerializer->unserialize($compactToken);

            if ($jwe->countRecipients() !== 1) {
                throw new InvalidArgumentException('Unexpected JWE recipient count.');
            }

            $outerKid = $this->requiredProtectedHeader($jwe->getSharedProtectedHeader(), 'kid');
            $outerAlgorithm = $this->requiredProtectedHeader($jwe->getSharedProtectedHeader(), 'alg');
            $outerEncryption = $this->requiredProtectedHeader($jwe->getSharedProtectedHeader(), 'enc');

            if (
                ! in_array($outerAlgorithm, SingpassAlgorithmProfile::jweKeyWrappingAlgorithms(), true)
                || $outerEncryption !== $profile['content_encryption']
            ) {
                throw new InvalidArgumentException('The JWE algorithm profile is invalid.');
            }

            $decryptionKey = $this->keyById($privateDecryptionJwks, $outerKid);
            $this->assertDecryptionKey($decryptionKey, $outerAlgorithm);

            $jweLoader = new JWELoader(
                new JWESerializerManager([$jweSerializer]),
                new JWEDecrypter($this->jweAlgorithmManager($profile['content_encryption'])),
                null,
            );
            $decrypted = $jweLoader->loadAndDecryptWithKey($compactToken, $decryptionKey, $recipient);

            if ($recipient !== 0 || ! is_string($decrypted->getPayload())) {
                throw new InvalidArgumentException('The JWE payload is invalid.');
            }

            $compactJws = $decrypted->getPayload();
            $jwsSerializer = new JwsCompactSerializer;
            $jws = $jwsSerializer->unserialize($compactJws);

            if ($jws->countSignatures() !== 1) {
                throw new InvalidArgumentException('Unexpected JWS signature count.');
            }

            $protectedHeader = $jws->getSignature(0)->getProtectedHeader();
            $innerKid = $this->requiredProtectedHeader($protectedHeader, 'kid');
            $innerAlgorithm = $this->requiredProtectedHeader($protectedHeader, 'alg');

            if ($innerAlgorithm !== $profile['signing']) {
                throw new InvalidArgumentException('The JWS algorithm profile is invalid.');
            }

            $signingKey = $this->keyById($publicSigningJwks, $innerKid);
            $this->assertSigningKey($signingKey, $innerAlgorithm);

            $jwsLoader = new JWSLoader(
                new JWSSerializerManager([$jwsSerializer]),
                new JWSVerifier(new AlgorithmManager([new ES256])),
                null,
            );
            $verified = $jwsLoader->loadAndVerifyWithKey($compactJws, $signingKey, $signature);

            if ($signature !== 0 || ! is_string($verified->getPayload())) {
                throw new InvalidArgumentException('The JWS payload is invalid.');
            }

            $payload = json_decode($verified->getPayload(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                throw new InvalidArgumentException('The JWT payload must be a JSON object or array.');
            }

            return $payload;
        } catch (InvalidIdTokenException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidIdTokenException('The ID token could not be decrypted and verified.');
        }
    }

    /**
     * @return array{content_encryption: string, signing: string}
     */
    private function profile(string $context): array
    {
        return match ($context) {
            self::ID_TOKEN => [
                'content_encryption' => SingpassAlgorithmProfile::idTokenContentEncryptionAlgorithms()[0],
                'signing' => SingpassAlgorithmProfile::idTokenSigningAlgorithms()[0],
            ],
            self::USERINFO => [
                'content_encryption' => SingpassAlgorithmProfile::userInfoContentEncryptionAlgorithms()[0],
                'signing' => SingpassAlgorithmProfile::userInfoSigningAlgorithms()[0],
            ],
            default => throw new InvalidArgumentException('Unknown nested JWT context.'),
        };
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function requiredProtectedHeader(array $headers, string $name): string
    {
        $value = $headers[$name] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The protected [{$name}] header is required.");
        }

        return $value;
    }

    private function keyById(JWKSet $keySet, string $kid): JWK
    {
        if (! $keySet->has($kid)) {
            throw new InvalidArgumentException('The token references an unknown key.');
        }

        return $keySet->get($kid);
    }

    private function assertDecryptionKey(JWK $key, string $algorithm): void
    {
        if (
            $key->get('use') !== 'enc'
            || $key->get('alg') !== $algorithm
            || $key->get('kty') !== 'EC'
            || ! $this->hasNonEmptyString($key, 'd')
        ) {
            throw new InvalidArgumentException('The referenced decryption key is invalid.');
        }
    }

    private function assertSigningKey(JWK $key, string $algorithm): void
    {
        if (
            $key->get('use') !== 'sig'
            || $key->get('kty') !== 'EC'
            || $key->get('crv') !== 'P-256'
            || $key->has('d')
            || ($key->has('alg') && $key->get('alg') !== $algorithm)
            || ! $this->hasNonEmptyString($key, 'x')
            || ! $this->hasNonEmptyString($key, 'y')
        ) {
            throw new InvalidArgumentException('The referenced signing key is invalid.');
        }
    }

    private function hasNonEmptyString(JWK $key, string $name): bool
    {
        return $key->has($name) && is_string($key->get($name)) && $key->get($name) !== '';
    }

    private function jweAlgorithmManager(string $contentEncryption): AlgorithmManager
    {
        $contentEncryptionAlgorithm = match ($contentEncryption) {
            'A256CBC-HS512' => new A256CBCHS512,
            'A256GCM' => new A256GCM,
            default => throw new InvalidArgumentException('Unsupported content-encryption algorithm.'),
        };

        return new AlgorithmManager([
            new ECDHESA128KW,
            new ECDHESA192KW,
            new ECDHESA256KW,
            $contentEncryptionAlgorithm,
        ]);
    }
}
