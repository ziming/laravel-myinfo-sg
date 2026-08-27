<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use RuntimeException;

final class JwkSetRotation
{
    public function __construct(
        private readonly JwkSetGenerator $generator,
        private readonly JwkSetValidator $validator
    ) {}

    /**
     * @param array<string, mixed> $privateJwks
     * @param array<string, mixed> $publicJwks
     * @return array{private: array{keys: list<array<string, mixed>>}, public: array{keys: list<array<string, mixed>>}, newKid: string}
     */
    public function prepareSigning(
        array $privateJwks,
        array $publicJwks,
        string $replaceKid,
        ?string $algorithm = null
    ): array {
        $this->validator->validatePair($publicJwks, $privateJwks);
        $privateKeys = $this->keys($privateJwks);
        $publicKeys = $this->keys($publicJwks);
        $oldPrivateKey = $this->requireKey($privateKeys, $replaceKid, 'sig', 'replacement');
        $this->requireKey($publicKeys, $replaceKid, 'sig', 'replacement');
        $newKey = $this->uniqueSigningKey(
            $algorithm ?? $this->requiredStringField($oldPrivateKey, 'alg', $replaceKid),
            $privateKeys,
            $publicKeys
        );

        $privateKeys[] = $newKey;
        $publicKeys[] = $this->generator->publicKey($newKey);
        $privateOutput = ['keys' => $privateKeys];
        $publicOutput = ['keys' => $publicKeys];
        $this->validator->validatePair($publicOutput, $privateOutput);

        return [
            'private' => $privateOutput,
            'public' => $publicOutput,
            'newKid' => $this->requiredStringField($newKey, 'kid', $replaceKid),
        ];
    }

    /**
     * @param array<string, mixed> $privateJwks
     * @param array<string, mixed> $publicJwks
     * @return array{private: array{keys: list<array<string, mixed>>}, public: array{keys: list<array<string, mixed>>}, newKid: string}
     */
    public function prepareEncryption(
        array $privateJwks,
        array $publicJwks,
        string $replaceKid,
        ?string $algorithm = null,
        ?string $curve = null
    ): array {
        $this->validator->validatePair($publicJwks, $privateJwks);
        $privateKeys = $this->keys($privateJwks);
        $publicKeys = $this->keys($publicJwks);
        $oldPrivateKey = $this->requireKey($privateKeys, $replaceKid, 'enc', 'replacement');
        $oldPublicIndex = $this->requireKeyIndex($publicKeys, $replaceKid, 'enc', 'replacement');
        $newKey = $this->uniqueEncryptionKey(
            $algorithm ?? $this->requiredStringField($oldPrivateKey, 'alg', $replaceKid),
            $curve ?? $this->requiredStringField($oldPrivateKey, 'crv', $replaceKid),
            $privateKeys,
            $publicKeys
        );

        $privateKeys[] = $newKey;
        $publicKeys[$oldPublicIndex] = $this->generator->publicKey($newKey);
        $privateOutput = ['keys' => $privateKeys];
        $publicOutput = ['keys' => $publicKeys];
        $this->validator->validatePair($publicOutput, $privateOutput);

        return [
            'private' => $privateOutput,
            'public' => $publicOutput,
            'newKid' => $this->requiredStringField($newKey, 'kid', $replaceKid),
        ];
    }

    /**
     * @param array<string, mixed> $privateJwks
     * @param array<string, mixed> $publicJwks
     * @return array{private: array{keys: list<array<string, mixed>>}, public: array{keys: list<array<string, mixed>>}, activeKid: string}
     */
    public function finalizeSigning(
        array $privateJwks,
        array $publicJwks,
        string $replaceKid,
        string $activeSigningKid
    ): array {
        $this->validator->validatePair($publicJwks, $privateJwks);
        $privateKeys = $this->keys($privateJwks);
        $publicKeys = $this->keys($publicJwks);
        $this->requireKey($privateKeys, $replaceKid, 'sig', 'replacement');
        $this->requireKey($publicKeys, $replaceKid, 'sig', 'replacement');

        if ($replaceKid === $activeSigningKid) {
            throw new RuntimeException('The active signing kid must be different from the signing kid being retired.');
        }

        $this->requireKey($privateKeys, $activeSigningKid, 'sig', 'active signing');
        $this->requireKey($publicKeys, $activeSigningKid, 'sig', 'active signing');
        $privateKeys = $this->withoutKey($privateKeys, $replaceKid);
        $publicKeys = $this->withoutKey($publicKeys, $replaceKid);

        if ($this->countRole($privateKeys, 'sig') === 0 || $this->countRole($publicKeys, 'sig') === 0) {
            throw new RuntimeException('Signing finalization cannot remove the last signing key.');
        }

        $privateOutput = ['keys' => $privateKeys];
        $publicOutput = ['keys' => $publicKeys];
        $this->validator->validatePair($publicOutput, $privateOutput);

        return [
            'private' => $privateOutput,
            'public' => $publicOutput,
            'activeKid' => $activeSigningKid,
        ];
    }

    /**
     * @param array<string, mixed> $privateJwks
     * @param array<string, mixed> $publicJwks
     * @return array{private: array{keys: list<array<string, mixed>>}, public: array{keys: list<array<string, mixed>>}}
     */
    public function finalizeEncryption(array $privateJwks, array $publicJwks, string $replaceKid): array
    {
        $this->validator->validatePair($publicJwks, $privateJwks);
        $privateKeys = $this->keys($privateJwks);
        $publicKeys = $this->keys($publicJwks);
        $this->requireKey($privateKeys, $replaceKid, 'enc', 'replacement');

        if ($this->findKeyIndex($publicKeys, $replaceKid) !== null) {
            throw new RuntimeException(
                "Encryption key [{$replaceKid}] is still public; publish its replacement and wait at least one hour before finalizing."
            );
        }

        $privateKeys = $this->withoutKey($privateKeys, $replaceKid);

        if ($this->countRole($privateKeys, 'enc') === 0) {
            throw new RuntimeException('Encryption finalization cannot remove the last encryption key.');
        }

        $privateOutput = ['keys' => $privateKeys];
        $publicOutput = ['keys' => $publicKeys];
        $this->validator->validatePair($publicOutput, $privateOutput);

        return [
            'private' => $privateOutput,
            'public' => $publicOutput,
        ];
    }

    /**
     * @param array<string, mixed> $jwks
     * @return list<array<string, mixed>>
     */
    private function keys(array $jwks): array
    {
        $keys = $jwks['keys'] ?? null;

        if (! is_array($keys) || ! array_is_list($keys)) {
            throw new RuntimeException('Invalid JWKS field [keys]: a non-empty list is required.');
        }

        $validatedKeys = [];

        foreach ($keys as $index => $key) {
            if (! is_array($key)) {
                throw new RuntimeException(
                    "Invalid JWKS field [keys] for key at index [{$index}]: an object is required."
                );
            }

            $validatedKeys[] = $key;
        }

        return $validatedKeys;
    }

    /**
     * @param list<array<string, mixed>> $keys
     * @return array<string, mixed>
     */
    private function requireKey(array $keys, string $kid, string $role, string $label): array
    {
        $index = $this->requireKeyIndex($keys, $kid, $role, $label);

        return $keys[$index];
    }

    /**
     * @param list<array<string, mixed>> $keys
     */
    private function requireKeyIndex(array $keys, string $kid, string $role, string $label): int
    {
        $index = $this->findKeyIndex($keys, $kid);

        if ($index === null) {
            throw new RuntimeException("The {$label} kid [{$kid}] is not present in both JWKS inputs.");
        }

        if (($keys[$index]['use'] ?? null) !== $role) {
            $roleName = $role === 'sig' ? 'signing' : 'encryption';

            throw new RuntimeException("The {$label} kid [{$kid}] must identify a {$roleName} key.");
        }

        return $index;
    }

    /**
     * @param list<array<string, mixed>> $keys
     */
    private function findKeyIndex(array $keys, string $kid): ?int
    {
        foreach ($keys as $index => $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $key
     */
    private function requiredStringField(array $key, string $field, string $kid): string
    {
        $value = $key[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Invalid JWKS field [{$field}] for key [{$kid}].");
        }

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $privateKeys
     * @param list<array<string, mixed>> $publicKeys
     * @return array<string, mixed>
     */
    private function uniqueSigningKey(string $algorithm, array $privateKeys, array $publicKeys): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $key = $this->generator->signingKey($algorithm);

            if (! $this->kidExists($key, $privateKeys, $publicKeys)) {
                return $key;
            }
        }

        throw new RuntimeException('Unable to generate a signing key with a unique kid.');
    }

    /**
     * @param list<array<string, mixed>> $privateKeys
     * @param list<array<string, mixed>> $publicKeys
     * @return array<string, mixed>
     */
    private function uniqueEncryptionKey(
        string $algorithm,
        string $curve,
        array $privateKeys,
        array $publicKeys
    ): array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $key = $this->generator->encryptionKey($algorithm, $curve);

            if (! $this->kidExists($key, $privateKeys, $publicKeys)) {
                return $key;
            }
        }

        throw new RuntimeException('Unable to generate an encryption key with a unique kid.');
    }

    /**
     * @param array<string, mixed> $key
     * @param list<array<string, mixed>> $privateKeys
     * @param list<array<string, mixed>> $publicKeys
     */
    private function kidExists(array $key, array $privateKeys, array $publicKeys): bool
    {
        $kid = $key['kid'] ?? null;

        if (! is_string($kid)) {
            return true;
        }

        return $this->findKeyIndex($privateKeys, $kid) !== null
            || $this->findKeyIndex($publicKeys, $kid) !== null;
    }

    /**
     * @param list<array<string, mixed>> $keys
     * @return list<array<string, mixed>>
     */
    private function withoutKey(array $keys, string $kid): array
    {
        return array_values(array_filter(
            $keys,
            static fn (array $key): bool => ($key['kid'] ?? null) !== $kid
        ));
    }

    /**
     * @param list<array<string, mixed>> $keys
     */
    private function countRole(array $keys, string $role): int
    {
        return count(array_filter(
            $keys,
            static fn (array $key): bool => ($key['use'] ?? null) === $role
        ));
    }
}
