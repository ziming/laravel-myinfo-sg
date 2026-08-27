<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetFileStore;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetGenerator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\SingpassAlgorithmProfile;

class GenerateJwkSetCommand extends Command
{
    private const string KEY_SELECTION_BOTH = 'both';

    private const string KEY_SELECTION_SIGNING = 'signing';

    private const string KEY_SELECTION_ENCRYPTION = 'encryption';

    protected $signature = 'myinfo:generate-jwks
        {--keys=both : Keys to generate: both, signing, or encryption}
        {--configure : Choose supported algorithms and curves in a guided flow}
        {--signing-alg=ES256 : Signing algorithm: ES256, ES384, or ES512}
        {--encryption-alg=ECDH-ES+A128KW : Encryption algorithm: ECDH-ES+A128KW, ECDH-ES+A192KW, or ECDH-ES+A256KW}
        {--encryption-curve=P-256 : Encryption curve: P-256, P-384, or P-521}
        {--private-output= : File path for the private JWKS (recommended)}
        {--public-output= : Optional file path for the public JWKS}
        {--show-private : Print private key material to the console instead of writing a file}
        {--force : Overwrite existing output files}';

    protected $description = 'Generate private and public Singpass-compatible JSON Web Key Sets';

    public function handle(JwkSetGenerator $generator, JwkSetFileStore $fileStore): int
    {
        $keySelection = $this->stringOption('keys') ?? self::KEY_SELECTION_BOTH;
        $signingAlgorithm = $this->stringOption('signing-alg') ?? JwkSetGenerator::DEFAULT_SIGNING_ALGORITHM;
        $encryptionAlgorithm = $this->stringOption('encryption-alg') ?? JwkSetGenerator::DEFAULT_ENCRYPTION_ALGORITHM;
        $encryptionCurve = $this->stringOption('encryption-curve') ?? JwkSetGenerator::DEFAULT_ENCRYPTION_CURVE;
        $privateOutput = $this->stringOption('private-output');
        $publicOutput = $this->stringOption('public-output');
        $configure = $this->option('configure') === true;
        $showPrivate = $this->option('show-private') === true;
        $force = $this->option('force') === true;

        if (! in_array($keySelection, $this->keySelections(), true)) {
            $this->error('The --keys option must be one of: both, signing, encryption.');

            return self::INVALID;
        }

        $signingAlgorithms = SingpassAlgorithmProfile::clientAssertionSigningAlgorithms();
        $encryptionAlgorithms = SingpassAlgorithmProfile::jweKeyWrappingAlgorithms();
        $encryptionCurves = SingpassAlgorithmProfile::encryptionKeyCurves();

        if ($this->includesSigningKey($keySelection) && ! array_key_exists($signingAlgorithm, $signingAlgorithms)) {
            $this->error('The --signing-alg option must be one of: '.implode(', ', array_keys($signingAlgorithms)).'.');

            return self::INVALID;
        }

        if ($this->includesEncryptionKey($keySelection) && ! in_array($encryptionAlgorithm, $encryptionAlgorithms, true)) {
            $this->error('The --encryption-alg option must be one of: '.implode(', ', $encryptionAlgorithms).'.');

            return self::INVALID;
        }

        if ($this->includesEncryptionKey($keySelection) && ! in_array($encryptionCurve, $encryptionCurves, true)) {
            $this->error('The --encryption-curve option must be one of: '.implode(', ', $encryptionCurves).'.');

            return self::INVALID;
        }

        if ($privateOutput === null && ! $showPrivate) {
            $this->error(
                'Choose a destination for private key material with --private-output=<path> '
                .'(recommended), or explicitly use --show-private.'
            );

            return self::INVALID;
        }

        if ($privateOutput !== null && $showPrivate) {
            $this->error('Use either --private-output or --show-private, not both.');

            return self::INVALID;
        }

        if ($privateOutput !== null && $privateOutput === $publicOutput) {
            $this->error('Private and public JWKS output paths must be different.');

            return self::INVALID;
        }

        $outputPaths = array_filter(
            [$privateOutput, $publicOutput],
            static fn (?string $path): bool => $path !== null
        );

        try {
            if ($privateOutput !== null && $publicOutput !== null) {
                $fileStore->assertDistinctRotationPaths($privateOutput, $publicOutput, []);
            }

            foreach ($outputPaths as $outputPath) {
                $fileStore->assertOutputPath($outputPath, $force, suggestForce: true);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($configure) {
            [$signingAlgorithm, $encryptionAlgorithm, $encryptionCurve] = $this->configureAlgorithms(
                $keySelection,
                $signingAlgorithm,
                $encryptionAlgorithm,
                $encryptionCurve
            );

            if (! $this->confirm('Generate the selected key material?', true)) {
                $this->comment('JWKS generation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $privateKeys = [];

            if ($this->includesSigningKey($keySelection)) {
                $privateKeys[] = $generator->signingKey($signingAlgorithm);
            }

            if ($this->includesEncryptionKey($keySelection)) {
                $privateKeys[] = $generator->encryptionKey($encryptionAlgorithm, $encryptionCurve);
            }

            $privateJwks = ['keys' => $privateKeys];
            $publicJwks = ['keys' => array_map($generator->publicKey(...), $privateKeys)];

            if ($privateOutput !== null) {
                $fileStore->write($privateOutput, $privateJwks, private: true, overwrite: $force);
                $this->info("Private JWKS written to [{$privateOutput}] with owner-only permissions.");
            } else {
                $this->warn('Private key material follows. Do not publish it or expose it through a JWKS endpoint.');
                $this->line("MYINFO_V5_PRIVATE_JWKS='{$fileStore->encode($privateJwks)}'");
            }

            if ($publicOutput !== null) {
                $fileStore->write($publicOutput, $publicJwks, private: false, overwrite: $force);
                $this->info("Public JWKS written to [{$publicOutput}].");
            } else {
                $this->line("MYINFO_V5_PUBLIC_JWKS='{$fileStore->encode($publicJwks)}'");
            }
        } catch (Throwable $exception) {
            $this->error('Unable to write the generated JWKS: '.$exception->getMessage());

            return self::FAILURE;
        }

        $signingKey = $this->findKeyForUse($privateKeys, 'sig');

        if ($signingKey !== null) {
            $this->line('MYINFO_V5_CHOSEN_JWKS_SIG_KID='.$signingKey['kid']);
        }

        $this->displayRotationGuidance($keySelection);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function keySelections(): array
    {
        return [
            self::KEY_SELECTION_BOTH,
            self::KEY_SELECTION_SIGNING,
            self::KEY_SELECTION_ENCRYPTION,
        ];
    }

    /**
     * @return array{string, string, string}
     */
    private function configureAlgorithms(
        string $keySelection,
        string $signingAlgorithm,
        string $encryptionAlgorithm,
        string $encryptionCurve
    ): array {
        $this->newLine();
        $this->info('Configure Singpass JWKS algorithms');

        if ($this->includesSigningKey($keySelection)) {
            $signingAlgorithms = SingpassAlgorithmProfile::clientAssertionSigningAlgorithms();
            $signingAlgorithm = (string) $this->choice(
                'Choose the client-assertion signing algorithm',
                array_keys($signingAlgorithms),
                $signingAlgorithm
            );
            $this->line('Signing curve: '.SingpassAlgorithmProfile::clientAssertionCurve($signingAlgorithm).' (selected by '.$signingAlgorithm.')');
        }

        if ($this->includesEncryptionKey($keySelection)) {
            $encryptionAlgorithm = (string) $this->choice(
                'Choose the JWE key-encryption algorithm',
                SingpassAlgorithmProfile::jweKeyWrappingAlgorithms(),
                $encryptionAlgorithm
            );
            $encryptionCurve = (string) $this->choice(
                'Choose the encryption-key curve',
                SingpassAlgorithmProfile::encryptionKeyCurves(),
                $encryptionCurve
            );
        }

        $this->newLine();
        $this->info('Selected key configuration');

        if ($this->includesSigningKey($keySelection)) {
            $this->line("Signing: {$signingAlgorithm} / ".SingpassAlgorithmProfile::clientAssertionCurve($signingAlgorithm));
        }

        if ($this->includesEncryptionKey($keySelection)) {
            $this->line("Encryption: {$encryptionAlgorithm} / {$encryptionCurve}");
        }

        return [$signingAlgorithm, $encryptionAlgorithm, $encryptionCurve];
    }

    private function includesSigningKey(string $keySelection): bool
    {
        return in_array($keySelection, [self::KEY_SELECTION_BOTH, self::KEY_SELECTION_SIGNING], true);
    }

    private function includesEncryptionKey(string $keySelection): bool
    {
        return in_array($keySelection, [self::KEY_SELECTION_BOTH, self::KEY_SELECTION_ENCRYPTION], true);
    }

    /**
     * @param list<array<string, mixed>> $keys
     * @return array<string, mixed>|null
     */
    private function findKeyForUse(array $keys, string $use): ?array
    {
        foreach ($keys as $key) {
            if (($key['use'] ?? null) === $use) {
                return $key;
            }
        }

        return null;
    }

    private function displayRotationGuidance(string $keySelection): void
    {
        if ($keySelection === self::KEY_SELECTION_BOTH) {
            $this->newLine();
            $this->comment(
                'For key rotation, generate one JWKS fragment at a time with '
                .'--keys=signing or --keys=encryption and follow the staged instructions.'
            );

            return;
        }

        $this->newLine();
        $this->warn('This is a JWKS fragment. Merge it with the appropriate existing keys; do not register it alone.');

        if ($keySelection === self::KEY_SELECTION_SIGNING) {
            $this->line('Signing rotation:');
            $this->line('1. Add the new public signing key alongside the old public signing key.');
            $this->line('2. Wait at least one hour for the Singpass JWKS cache to expire.');
            $this->line('3. Deploy the new private key and select its MYINFO_V5_CHOSEN_JWKS_SIG_KID.');
            $this->line('4. Remove the old public and private signing keys.');

            return;
        }

        $this->line('Encryption rotation:');
        $this->line('1. Add the new private encryption key while retaining the old private encryption key.');
        $this->line('2. Replace the old public encryption key with the new public encryption key.');
        $this->line('3. Wait at least one hour for the Singpass JWKS cache to expire.');
        $this->line('4. Remove the old private encryption key.');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
