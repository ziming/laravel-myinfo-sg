<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Ziming\LaravelMyinfoSg\Console\Commands\RotateJwkSetCommand;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetFileStore;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetGenerator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetValidator;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class RotateJwkSetCommandTest extends TestCase
{
    private Filesystem $files;

    private JwkSetGenerator $generator;

    private JwkSetValidator $validator;

    private string $temporaryDirectory;

    private string $privateInput;

    private string $publicInput;

    /** @var array{keys: list<array<string, mixed>>} */
    private array $privateJwks;

    /** @var array{keys: list<array<string, mixed>>} */
    private array $publicJwks;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->generator = new JwkSetGenerator;
        $this->validator = new JwkSetValidator;
        $this->temporaryDirectory = sys_get_temp_dir().'/laravel-myinfo-rotation-'.Str::uuid();
        $this->files->makeDirectory($this->temporaryDirectory, 0700, true);
        $this->privateInput = $this->temporaryDirectory.'/private-input.json';
        $this->publicInput = $this->temporaryDirectory.'/public-input.json';
        $privateKeys = [$this->generator->signingKey(), $this->generator->encryptionKey()];
        $this->privateJwks = ['keys' => $privateKeys];
        $this->publicJwks = ['keys' => array_map($this->generator->publicKey(...), $privateKeys)];
        $this->writePair($this->privateInput, $this->publicInput, $this->privateJwks, $this->publicJwks);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_signing_prepare_and_finalize_produce_complete_valid_pairs(): void
    {
        $oldKid = (string) $this->privateJwks['keys'][0]['kid'];
        [$preparedPrivatePath, $preparedPublicPath] = $this->outputPaths('signing-prepared');
        $prepareTester = $this->executeCommand($this->rotationOptions(
            'prepare',
            'signing',
            $oldKid,
            $this->privateInput,
            $this->publicInput,
            $preparedPrivatePath,
            $preparedPublicPath
        ));

        $this->assertSame(0, $prepareTester->getStatusCode(), $prepareTester->getDisplay());
        $preparedPrivate = $this->decode($preparedPrivatePath);
        $preparedPublic = $this->decode($preparedPublicPath);
        $newPrivateSigningKeys = $this->keysForRole($preparedPrivate, 'sig');
        $newPublicSigningKeys = $this->keysForRole($preparedPublic, 'sig');
        $this->assertCount(2, $newPrivateSigningKeys);
        $this->assertCount(2, $newPublicSigningKeys);
        $this->assertCount(1, $this->keysForRole($preparedPrivate, 'enc'));
        $this->assertSame('ES256', $newPrivateSigningKeys[1]['alg']);
        $this->assertSame('P-256', $newPrivateSigningKeys[1]['crv']);
        $this->assertNotSame($oldKid, $newPrivateSigningKeys[1]['kid']);
        $this->assertArrayNotHasKey('d', $newPublicSigningKeys[1]);
        $this->validator->validatePair($preparedPublic, $preparedPrivate);
        $newKid = (string) $newPrivateSigningKeys[1]['kid'];
        $this->assertStringContainsString("New signing kid: {$newKid}", $prepareTester->getDisplay());
        $this->assertStringContainsString('wait at least one hour', $prepareTester->getDisplay());
        $this->assertStringNotContainsString((string) $newPrivateSigningKeys[1]['d'], $prepareTester->getDisplay());

        [$finalPrivatePath, $finalPublicPath] = $this->outputPaths('signing-final');
        $finalizeTester = $this->executeCommand([
            ...$this->rotationOptions(
                'finalize',
                'signing',
                $oldKid,
                $preparedPrivatePath,
                $preparedPublicPath,
                $finalPrivatePath,
                $finalPublicPath
            ),
            '--active-signing-kid' => $newKid,
            '--confirm-cache-expired' => true,
        ]);

        $this->assertSame(0, $finalizeTester->getStatusCode(), $finalizeTester->getDisplay());
        $finalPrivate = $this->decode($finalPrivatePath);
        $finalPublic = $this->decode($finalPublicPath);
        $this->assertFalse($this->hasKid($finalPrivate, $oldKid));
        $this->assertFalse($this->hasKid($finalPublic, $oldKid));
        $this->assertTrue($this->hasKid($finalPrivate, $newKid));
        $this->assertTrue($this->hasKid($finalPublic, $newKid));
        $this->validator->validatePair($finalPublic, $finalPrivate);
        $this->assertStringContainsString("Active public signing kid: {$newKid}", $finalizeTester->getDisplay());
        $this->assertSafeModes($finalPrivatePath, $finalPublicPath);
    }

    public function test_signing_prepare_accepts_a_supported_algorithm_override(): void
    {
        [$privateOutput, $publicOutput] = $this->outputPaths('signing-override');
        $tester = $this->executeCommand([
            ...$this->rotationOptions(
                'prepare',
                'signing',
                (string) $this->privateJwks['keys'][0]['kid'],
                $this->privateInput,
                $this->publicInput,
                $privateOutput,
                $publicOutput
            ),
            '--signing-alg' => 'ES384',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $keys = $this->keysForRole($this->decode($privateOutput), 'sig');
        $this->assertSame('ES384', $keys[1]['alg']);
        $this->assertSame('P-384', $keys[1]['crv']);
    }

    public function test_encryption_prepare_and_finalize_preserve_the_required_overlap(): void
    {
        $unaffectedEncryption = $this->generator->encryptionKey('ECDH-ES+A192KW', 'P-384');
        $this->privateJwks['keys'][] = $unaffectedEncryption;
        $this->publicJwks['keys'][] = $this->generator->publicKey($unaffectedEncryption);
        $this->writePair($this->privateInput, $this->publicInput, $this->privateJwks, $this->publicJwks);
        $oldKid = (string) $this->privateJwks['keys'][1]['kid'];
        [$preparedPrivatePath, $preparedPublicPath] = $this->outputPaths('encryption-prepared');
        $prepareTester = $this->executeCommand([
            ...$this->rotationOptions(
                'prepare',
                'encryption',
                $oldKid,
                $this->privateInput,
                $this->publicInput,
                $preparedPrivatePath,
                $preparedPublicPath
            ),
            '--encryption-alg' => 'ECDH-ES+A256KW',
            '--encryption-curve' => 'P-521',
        ]);

        $this->assertSame(0, $prepareTester->getStatusCode(), $prepareTester->getDisplay());
        $preparedPrivate = $this->decode($preparedPrivatePath);
        $preparedPublic = $this->decode($preparedPublicPath);
        $this->assertTrue($this->hasKid($preparedPrivate, $oldKid));
        $this->assertFalse($this->hasKid($preparedPublic, $oldKid));
        $this->assertTrue($this->hasKid($preparedPrivate, (string) $unaffectedEncryption['kid']));
        $this->assertTrue($this->hasKid($preparedPublic, (string) $unaffectedEncryption['kid']));
        $this->assertContains($unaffectedEncryption, $preparedPrivate['keys']);
        $this->assertContains($this->generator->publicKey($unaffectedEncryption), $preparedPublic['keys']);
        $preparedEncryptionKeys = $this->keysForRole($preparedPrivate, 'enc');
        $newKey = $preparedEncryptionKeys[2];
        $this->assertSame('ECDH-ES+A256KW', $newKey['alg']);
        $this->assertSame('P-521', $newKey['crv']);
        $this->assertTrue($this->hasKid($preparedPublic, (string) $newKey['kid']));
        $this->validator->validatePair($preparedPublic, $preparedPrivate);
        $this->assertStringContainsString('deploy the private overlap', $prepareTester->getDisplay());
        $this->assertStringNotContainsString((string) $newKey['d'], $prepareTester->getDisplay());

        [$finalPrivatePath, $finalPublicPath] = $this->outputPaths('encryption-final');
        $finalizeTester = $this->executeCommand([
            ...$this->rotationOptions(
                'finalize',
                'encryption',
                $oldKid,
                $preparedPrivatePath,
                $preparedPublicPath,
                $finalPrivatePath,
                $finalPublicPath
            ),
            '--confirm-cache-expired' => true,
        ]);

        $this->assertSame(0, $finalizeTester->getStatusCode(), $finalizeTester->getDisplay());
        $finalPrivate = $this->decode($finalPrivatePath);
        $finalPublic = $this->decode($finalPublicPath);
        $this->assertFalse($this->hasKid($finalPrivate, $oldKid));
        $this->assertSame($preparedPublic, $finalPublic);
        $this->validator->validatePair($finalPublic, $finalPrivate);
        $this->assertStringContainsString('Active public encryption kids:', $finalizeTester->getDisplay());
        $this->assertSafeModes($finalPrivatePath, $finalPublicPath);
    }

    public function test_encryption_prepare_inherits_the_replaced_keys_algorithm_and_curve(): void
    {
        $oldEncryption = $this->generator->encryptionKey('ECDH-ES+A192KW', 'P-384');
        $this->privateJwks['keys'][1] = $oldEncryption;
        $this->publicJwks['keys'][1] = $this->generator->publicKey($oldEncryption);
        $this->writePair($this->privateInput, $this->publicInput, $this->privateJwks, $this->publicJwks);
        [$privateOutput, $publicOutput] = $this->outputPaths('encryption-inherited');
        $tester = $this->executeCommand($this->rotationOptions(
            'prepare',
            'encryption',
            (string) $oldEncryption['kid'],
            $this->privateInput,
            $this->publicInput,
            $privateOutput,
            $publicOutput
        ));

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $newKey = $this->keysForRole($this->decode($privateOutput), 'enc')[1];
        $this->assertSame('ECDH-ES+A192KW', $newKey['alg']);
        $this->assertSame('P-384', $newKey['crv']);
    }

    public function test_finalize_requires_confirmation_and_valid_staged_state(): void
    {
        $oldSigningKid = (string) $this->privateJwks['keys'][0]['kid'];
        $oldEncryptionKid = (string) $this->privateJwks['keys'][1]['kid'];
        [$privateOutput, $publicOutput] = $this->outputPaths('unconfirmed');
        $tester = $this->executeCommand([
            ...$this->rotationOptions(
                'finalize',
                'signing',
                $oldSigningKid,
                $this->privateInput,
                $this->publicInput,
                $privateOutput,
                $publicOutput
            ),
            '--active-signing-kid' => 'sig-missing',
        ], interactive: false);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertFileDoesNotExist($privateOutput);
        $this->assertFileDoesNotExist($publicOutput);

        [$privateOutput, $publicOutput] = $this->outputPaths('premature-encryption');
        $tester = $this->executeCommand([
            ...$this->rotationOptions(
                'finalize',
                'encryption',
                $oldEncryptionKid,
                $this->privateInput,
                $this->publicInput,
                $privateOutput,
                $publicOutput
            ),
            '--confirm-cache-expired' => true,
        ]);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('still public', $tester->getDisplay());
        $this->assertFileDoesNotExist($privateOutput);
    }

    public function test_finalize_refuses_without_cache_confirmation_in_non_interactive_mode(): void
    {
        $oldKid = (string) $this->privateJwks['keys'][0]['kid'];
        [$preparedPrivatePath, $preparedPublicPath] = $this->outputPaths('confirmation-prepared');
        $prepareTester = $this->executeCommand($this->rotationOptions(
            'prepare',
            'signing',
            $oldKid,
            $this->privateInput,
            $this->publicInput,
            $preparedPrivatePath,
            $preparedPublicPath
        ));
        $this->assertSame(0, $prepareTester->getStatusCode());
        $newKid = (string) $this->keysForRole($this->decode($preparedPrivatePath), 'sig')[1]['kid'];
        [$privateOutput, $publicOutput] = $this->outputPaths('confirmation-final');
        $tester = $this->executeCommand([
            ...$this->rotationOptions(
                'finalize',
                'signing',
                $oldKid,
                $preparedPrivatePath,
                $preparedPublicPath,
                $privateOutput,
                $publicOutput
            ),
            '--active-signing-kid' => $newKid,
        ], interactive: false);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('one-hour Singpass cache window', $tester->getDisplay());
        $this->assertFileDoesNotExist($privateOutput);
        $this->assertFileDoesNotExist($publicOutput);
    }

    public function test_rotation_rejects_unsafe_paths_without_changing_inputs_or_existing_outputs(): void
    {
        $originalPrivate = $this->files->get($this->privateInput);
        $originalPublic = $this->files->get($this->publicInput);
        $existingOutput = $this->temporaryDirectory.'/existing.json';
        $this->files->put($existingOutput, 'existing-content');
        $oldKid = (string) $this->privateJwks['keys'][0]['kid'];

        $cases = [
            [$this->privateInput, $this->temporaryDirectory.'/new-public.json'],
            [$existingOutput, $this->temporaryDirectory.'/new-public-2.json'],
            [$this->temporaryDirectory, $this->temporaryDirectory.'/new-public-3.json'],
        ];

        foreach ($cases as [$privateOutput, $publicOutput]) {
            $tester = $this->executeCommand($this->rotationOptions(
                'prepare',
                'signing',
                $oldKid,
                $this->privateInput,
                $this->publicInput,
                $privateOutput,
                $publicOutput
            ));
            $this->assertSame(2, $tester->getStatusCode());
        }

        $symlinkOutput = $this->temporaryDirectory.'/output-link.json';
        symlink($existingOutput, $symlinkOutput);
        $tester = $this->executeCommand($this->rotationOptions(
            'prepare',
            'signing',
            $oldKid,
            $this->privateInput,
            $this->publicInput,
            $symlinkOutput,
            $this->temporaryDirectory.'/new-public-4.json'
        ));
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertSame($originalPrivate, $this->files->get($this->privateInput));
        $this->assertSame($originalPublic, $this->files->get($this->publicInput));
        $this->assertSame('existing-content', $this->files->get($existingOutput));
    }

    public function test_pair_write_failure_removes_partial_outputs_and_only_its_temporary_files(): void
    {
        $privateOutput = $this->temporaryDirectory.'/failure-private.json';
        $publicOutput = $this->temporaryDirectory.'/failure-public.json';
        $moveCount = 0;
        $failingFiles = new class($moveCount) extends Filesystem
        {
            private int $moveCount;

            public function __construct(int &$moveCount)
            {
                $this->moveCount = &$moveCount;
            }

            public function move($path, $target)
            {
                $this->moveCount++;

                if ($this->moveCount === 2) {
                    throw new RuntimeException('injected publication failure');
                }

                return parent::move($path, $target);
            }
        };
        $store = new JwkSetFileStore($failingFiles, new JwkSetValidator);

        try {
            $store->writePair(
                $privateOutput,
                $publicOutput,
                $this->privateJwks,
                $this->publicJwks,
                [$this->privateInput, $this->publicInput]
            );
            $this->fail('Expected the injected second publication failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('injected publication failure', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($privateOutput);
        $this->assertFileDoesNotExist($publicOutput);
        $this->assertSame([], glob($this->temporaryDirectory.'/.*.tmp-*') ?: []);
        $this->assertFileExists($this->privateInput);
        $this->assertFileExists($this->publicInput);
    }

    /**
     * @return array<string, mixed>
     */
    private function rotationOptions(
        string $stage,
        string $role,
        string $replaceKid,
        string $privateInput,
        string $publicInput,
        string $privateOutput,
        string $publicOutput
    ): array {
        return [
            '--stage' => $stage,
            '--role' => $role,
            '--replace-kid' => $replaceKid,
            '--private-input' => $privateInput,
            '--public-input' => $publicInput,
            '--private-output' => $privateOutput,
            '--public-output' => $publicOutput,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeCommand(array $input, bool $interactive = true): CommandTester
    {
        $command = $this->app->make(RotateJwkSetCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => $interactive]);

        return $tester;
    }

    /**
     * @param array{keys: list<array<string, mixed>>} $privateJwks
     * @param array{keys: list<array<string, mixed>>} $publicJwks
     */
    private function writePair(string $privatePath, string $publicPath, array $privateJwks, array $publicJwks): void
    {
        $this->files->put($privatePath, json_encode($privateJwks, JSON_THROW_ON_ERROR));
        $this->files->put($publicPath, json_encode($publicJwks, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    private function decode(string $path): array
    {
        return json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{keys: list<array<string, mixed>>} $jwks
     * @return list<array<string, mixed>>
     */
    private function keysForRole(array $jwks, string $role): array
    {
        return array_values(array_filter(
            $jwks['keys'],
            static fn (array $key): bool => ($key['use'] ?? null) === $role
        ));
    }

    /**
     * @param array{keys: list<array<string, mixed>>} $jwks
     */
    private function hasKid(array $jwks, string $kid): bool
    {
        return array_any($jwks['keys'], static fn (array $key): bool => ($key['kid'] ?? null) === $kid);
    }

    /**
     * @return array{string, string}
     */
    private function outputPaths(string $prefix): array
    {
        return [
            $this->temporaryDirectory."/{$prefix}-private.json",
            $this->temporaryDirectory."/{$prefix}-public.json",
        ];
    }

    private function assertSafeModes(string $privatePath, string $publicPath): void
    {
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0600, fileperms($privatePath) & 0777);
            $this->assertSame(0644, fileperms($publicPath) & 0777);
        }
    }
}
