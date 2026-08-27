<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV5;

use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use JsonException;
use RuntimeException;

final class JwkSetValidator
{
    private const array SIGNING_KEY_OPERATIONS = ['sign', 'verify'];

    private const array ENCRYPTION_KEY_OPERATIONS = [
        'encrypt',
        'decrypt',
        'wrapKey',
        'unwrapKey',
        'deriveKey',
        'deriveBits',
    ];

    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    public function validatePublicJwks(mixed $rawJwks): array
    {
        $keys = $this->parseKeys($rawJwks);
        $validatedKeys = $this->validateKeys($keys, public: true);
        $uses = array_column($validatedKeys, 'use');

        foreach (['sig', 'enc'] as $requiredUse) {
            if (! in_array($requiredUse, $uses, true)) {
                throw new RuntimeException(
                    "Invalid public JWKS field [use]: at least one [{$requiredUse}] key is required."
                );
            }
        }

        return ['keys' => $validatedKeys];
    }

    public function validatePrivateJwks(mixed $rawJwks): JWKSet
    {
        $keys = $this->validateKeys($this->parseKeys($rawJwks), public: false);

        return new JWKSet(array_map(
            static fn (array $key): JWK => new JWK($key),
            $keys
        ));
    }

    public function validatePair(mixed $rawPublicJwks, mixed $rawPrivateJwks): void
    {
        $publicJwks = $this->validatePublicJwks($rawPublicJwks);
        $privateJwks = $this->validatePrivateJwks($rawPrivateJwks);

        /** @var array<string, JWK> $privateKeys */
        $privateKeys = [];

        foreach ($privateJwks->all() as $privateKey) {
            $privateKeys[$privateKey->get('kid')] = $privateKey;
        }

        foreach ($publicJwks['keys'] as $publicKey) {
            $kid = $publicKey['kid'];

            if (! isset($privateKeys[$kid])) {
                throw new RuntimeException(
                    "Invalid JWKS pair field [kid] for key [{$kid}]: no matching private key exists."
                );
            }

            $privateKey = $privateKeys[$kid]->all();

            foreach (['kty', 'crv', 'x', 'y', 'use'] as $field) {
                if ($publicKey[$field] !== $privateKey[$field]) {
                    throw new RuntimeException(
                        "Invalid JWKS pair field [{$field}] for key [{$kid}]: public and private values differ."
                    );
                }
            }

            if (isset($publicKey['alg']) && $publicKey['alg'] !== $privateKey['alg']) {
                throw new RuntimeException(
                    "Invalid JWKS pair field [alg] for key [{$kid}]: public and private values differ."
                );
            }

            unset($privateKeys[$kid]);
        }

        foreach ($privateKeys as $kid => $privateKey) {
            if ($privateKey->get('use') !== 'enc') {
                throw new RuntimeException(
                    "Invalid JWKS pair field [kid] for key [{$kid}]: an unmatched private signing key is not allowed."
                );
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseKeys(mixed $rawJwks): array
    {
        if (is_string($rawJwks) && trim($rawJwks) !== '') {
            try {
                $decoded = json_decode($rawJwks, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    'Invalid JWKS field [keys]: the configured value is not valid JSON.',
                    previous: $exception
                );
            }
        } elseif (is_array($rawJwks)) {
            $decoded = $rawJwks;
        } else {
            throw new RuntimeException(
                'Invalid JWKS field [keys]: the configured value must be a JSON string or array.'
            );
        }

        if (! is_array($decoded) || ! isset($decoded['keys']) || ! is_array($decoded['keys'])) {
            throw new RuntimeException('Invalid JWKS field [keys]: a non-empty list is required.');
        }

        if (! array_is_list($decoded['keys']) || $decoded['keys'] === []) {
            throw new RuntimeException('Invalid JWKS field [keys]: a non-empty list is required.');
        }

        $keys = [];

        foreach ($decoded['keys'] as $index => $key) {
            if (! is_array($key)) {
                throw new RuntimeException(
                    "Invalid JWKS field [keys] for key at index [{$index}]: an object is required."
                );
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param list<array<string, mixed>> $keys
     * @return list<array<string, mixed>>
     */
    private function validateKeys(array $keys, bool $public): array
    {
        $seenKeyIds = [];

        foreach ($keys as $index => $key) {
            $kid = $this->requireNonEmptyString($key, 'kid', $index);

            if (isset($seenKeyIds[$kid])) {
                throw new RuntimeException(
                    "Invalid JWKS field [kid] for key [{$kid}]: duplicate values are not allowed."
                );
            }

            $seenKeyIds[$kid] = true;
            $kty = $this->requireNonEmptyString($key, 'kty', $index, $kid);
            $curve = $this->requireNonEmptyString($key, 'crv', $index, $kid);
            $use = $this->requireNonEmptyString($key, 'use', $index, $kid);
            $this->requireNonEmptyString($key, 'x', $index, $kid);
            $this->requireNonEmptyString($key, 'y', $index, $kid);

            if ($kty !== 'EC') {
                $this->throwInvalidField('kty', $index, $kid, 'only [EC] keys are allowed');
            }

            if (! in_array($use, ['sig', 'enc'], true)) {
                $this->throwInvalidField('use', $index, $kid, 'only [sig] or [enc] is allowed');
            }

            $allowedCurves = $use === 'sig'
                ? array_values(SingpassAlgorithmProfile::clientAssertionSigningAlgorithms())
                : SingpassAlgorithmProfile::encryptionKeyCurves();

            if (! in_array($curve, $allowedCurves, true)) {
                $this->throwInvalidField('crv', $index, $kid, 'the curve is not allowed');
            }

            if ($public && array_key_exists('d', $key)) {
                $this->throwInvalidField('d', $index, $kid, 'private key material is not allowed in a public set');
            }

            if (! $public) {
                $this->requireNonEmptyString($key, 'd', $index, $kid);
            }

            $this->validateAlgorithm($key, $use, $curve, $public, $index, $kid);
            $this->validateKeyOperations($key, $use, $index, $kid);
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $key
     */
    private function validateAlgorithm(
        array $key,
        string $use,
        string $curve,
        bool $public,
        int $index,
        string $kid
    ): void {
        if ($use === 'enc') {
            $algorithm = $this->requireNonEmptyString($key, 'alg', $index, $kid);

            if (! in_array($algorithm, SingpassAlgorithmProfile::jweKeyWrappingAlgorithms(), true)) {
                $this->throwInvalidField('alg', $index, $kid, 'the encryption algorithm is not allowed');
            }

            return;
        }

        if ($public && ! array_key_exists('alg', $key)) {
            return;
        }

        $algorithm = $this->requireNonEmptyString($key, 'alg', $index, $kid);
        $signingAlgorithms = SingpassAlgorithmProfile::clientAssertionSigningAlgorithms();

        if (! isset($signingAlgorithms[$algorithm])) {
            $this->throwInvalidField('alg', $index, $kid, 'the signing algorithm is not allowed');
        }

        if ($curve !== $signingAlgorithms[$algorithm]) {
            $this->throwInvalidField('crv', $index, $kid, 'the curve does not match the signing algorithm');
        }
    }

    /**
     * @param array<string, mixed> $key
     */
    private function validateKeyOperations(array $key, string $use, int $index, string $kid): void
    {
        if (! array_key_exists('key_ops', $key)) {
            return;
        }

        $keyOperations = $key['key_ops'];

        if (! is_array($keyOperations) || ! array_is_list($keyOperations)) {
            $this->throwInvalidField('key_ops', $index, $kid, 'a list is required when present');
        }

        $allowedOperations = $use === 'sig'
            ? self::SIGNING_KEY_OPERATIONS
            : self::ENCRYPTION_KEY_OPERATIONS;

        foreach ($keyOperations as $operation) {
            if (! is_string($operation) || ! in_array($operation, $allowedOperations, true)) {
                $this->throwInvalidField('key_ops', $index, $kid, "the operations must be compatible with use [{$use}]");
            }
        }
    }

    /**
     * @param array<string, mixed> $key
     */
    private function requireNonEmptyString(
        array $key,
        string $field,
        int $index,
        ?string $kid = null
    ): string {
        if (! isset($key[$field]) || ! is_string($key[$field]) || trim($key[$field]) === '') {
            $this->throwInvalidField($field, $index, $kid, 'a non-empty string is required');
        }

        return $key[$field];
    }

    private function throwInvalidField(string $field, int $index, ?string $kid, string $reason): never
    {
        $keyLabel = $kid === null ? "key at index [{$index}]" : "key [{$kid}]";

        throw new RuntimeException("Invalid JWKS field [{$field}] for {$keyLabel}: {$reason}.");
    }
}
