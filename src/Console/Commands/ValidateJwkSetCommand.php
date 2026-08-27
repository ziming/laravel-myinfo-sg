<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetFileStore;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetValidator;

final class ValidateJwkSetCommand extends Command
{
    protected $signature = 'myinfo:validate-jwks
        {--private= : Path to the private JWKS file}
        {--public= : Path to the public JWKS file}
        {--signing-kid= : Optional selected private signing key id}';

    protected $description = 'Validate a private/public Singpass JWKS pair without exposing key material';

    public function handle(JwkSetFileStore $fileStore, JwkSetValidator $validator): int
    {
        try {
            $privatePath = $this->requiredOption('private');
            $publicPath = $this->requiredOption('public');
            $privateJwks = $fileStore->read($privatePath);
            $publicJwks = $fileStore->read($publicPath);
            $validator->validatePair($publicJwks, $privateJwks);
            $signingKid = $this->stringOption('signing-kid');

            if ($signingKid !== null) {
                $this->requireSigningKid($privateJwks, $publicJwks, $signingKid);
            }

            $privateKeys = $this->keys($privateJwks);
            $publicKeys = $this->keys($publicJwks);
            $this->info('JWKS pair is valid.');
            $this->line($this->countSummary('Private', $privateKeys));
            $this->line($this->countSummary('Public', $publicKeys));

            foreach ($publicKeys as $key) {
                $kid = $this->safeField($key, 'kid');
                $use = $this->safeField($key, 'use');
                $algorithm = isset($key['alg']) && is_string($key['alg']) ? $key['alg'] : '(not declared)';
                $curve = $this->safeField($key, 'crv');
                $this->line("Public key: kid={$kid}; use={$use}; alg={$algorithm}; crv={$curve}");
            }

            if ($signingKid !== null) {
                $this->info("Selected signing kid [{$signingKid}] is valid and has a matching public key.");
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->stringOption($name);

        if ($value === null) {
            throw new RuntimeException("The --{$name} option is required.");
        }

        return $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $privateJwks
     * @param array<string, mixed> $publicJwks
     */
    private function requireSigningKid(array $privateJwks, array $publicJwks, string $kid): void
    {
        foreach ([$this->keys($privateJwks), $this->keys($publicJwks)] as $keys) {
            $match = null;

            foreach ($keys as $key) {
                if (($key['kid'] ?? null) === $kid) {
                    $match = $key;
                    break;
                }
            }

            if ($match === null) {
                throw new RuntimeException("Selected signing kid [{$kid}] is not present in both JWKS files.");
            }

            if (($match['use'] ?? null) !== 'sig') {
                throw new RuntimeException("Selected signing kid [{$kid}] does not identify a signing key.");
            }
        }
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
     */
    private function countSummary(string $label, array $keys): string
    {
        $signing = count(array_filter($keys, static fn (array $key): bool => ($key['use'] ?? null) === 'sig'));
        $encryption = count(array_filter($keys, static fn (array $key): bool => ($key['use'] ?? null) === 'enc'));

        return "{$label} keys: ".count($keys)." (signing: {$signing}; encryption: {$encryption}).";
    }

    /**
     * @param array<string, mixed> $key
     */
    private function safeField(array $key, string $field): string
    {
        $value = $key[$field] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Invalid JWKS field [{$field}].");
        }

        return $value;
    }
}
