<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Jose\Component\Core\JWK;
use Symfony\Component\Console\Tester\CommandTester;
use Ziming\LaravelMyinfoSg\Console\Commands\GenerateJwkSetCommand;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GenerateJwkSetCommandTest extends TestCase
{
    private Filesystem $files;

    private string $temporaryDirectory;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->temporaryDirectory = sys_get_temp_dir().'/laravel-myinfo-sg-'.Str::uuid();
        $this->files->makeDirectory($this->temporaryDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_writes_private_jwks_and_outputs_matching_public_configuration(): void
    {
        $privateOutput = $this->temporaryDirectory.'/private.jwks.json';
        $tester = $this->executeCommand([
            '--private-output' => $privateOutput,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($privateOutput);

        $output = $this->normaliseOutput($tester->getDisplay());
        $privatePayload = $this->decodeJsonFile($privateOutput);
        $publicPayload = $this->extractJsonEnvironmentValue($output, 'MYINFO_V5_PUBLIC_JWKS');

        $this->assertStringContainsString(
            "Private JWKS written to [{$privateOutput}] with owner-only permissions.",
            $output
        );
        $this->assertStringNotContainsString('MYINFO_V5_PRIVATE_JWKS=', $output);
        $this->assertCount(2, $privatePayload['keys']);
        $this->assertCount(2, $publicPayload['keys']);

        $privateKeys = array_column($privatePayload['keys'], null, 'use');
        $publicKeys = array_column($publicPayload['keys'], null, 'use');

        $this->assertSame(['sig', 'enc'], array_keys($privateKeys));
        $this->assertSame(['sig', 'enc'], array_keys($publicKeys));
        $this->assertSame(['ES256', 'ECDH-ES+A128KW'], array_column($privatePayload['keys'], 'alg'));
        $this->assertSame(['EC', 'EC'], array_column($privatePayload['keys'], 'kty'));
        $this->assertSame(['P-256', 'P-256'], array_column($privatePayload['keys'], 'crv'));

        foreach (['sig', 'enc'] as $use) {
            $this->assertArrayHasKey('d', $privateKeys[$use]);
            $this->assertArrayNotHasKey('d', $publicKeys[$use]);
            $this->assertSame($privateKeys[$use]['kid'], $publicKeys[$use]['kid']);
            $this->assertSame($privateKeys[$use]['x'], $publicKeys[$use]['x']);
            $this->assertSame($privateKeys[$use]['y'], $publicKeys[$use]['y']);
        }

        $this->assertSame(
            'sig-'.(new JWK($privateKeys['sig']))->thumbprint('sha256'),
            $privateKeys['sig']['kid']
        );
        $this->assertSame(
            'enc-'.(new JWK($privateKeys['enc']))->thumbprint('sha256'),
            $privateKeys['enc']['kid']
        );
        $this->assertStringContainsString(
            'MYINFO_V5_CHOSEN_JWKS_SIG_KID='.$privateKeys['sig']['kid'],
            $output
        );

        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0600, fileperms($privateOutput) & 0777);
        }
    }

    public function test_it_requires_an_explicit_private_key_destination(): void
    {
        $tester = $this->executeCommand();

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('--private-output=<path>', $tester->getDisplay());
    }

    public function test_it_only_prints_private_key_material_when_explicitly_requested(): void
    {
        $tester = $this->executeCommand([
            '--show-private' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $output = $this->normaliseOutput($tester->getDisplay());
        $privatePayload = $this->extractJsonEnvironmentValue($output, 'MYINFO_V5_PRIVATE_JWKS');
        $publicPayload = $this->extractJsonEnvironmentValue($output, 'MYINFO_V5_PUBLIC_JWKS');

        $this->assertStringContainsString('Private key material follows.', $output);
        $this->assertArrayHasKey('d', $privatePayload['keys'][0]);
        $this->assertArrayNotHasKey('d', $publicPayload['keys'][0]);
    }

    public function test_it_refuses_to_overwrite_an_existing_private_jwks_without_force(): void
    {
        $privateOutput = $this->temporaryDirectory.'/private.jwks.json';
        $this->files->put($privateOutput, 'existing-content');

        $tester = $this->executeCommand([
            '--private-output' => $privateOutput,
        ]);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertSame('existing-content', $this->files->get($privateOutput));
        $this->assertStringContainsString('Use --force to overwrite it.', $tester->getDisplay());

        $tester = $this->executeCommand([
            '--private-output' => $privateOutput,
            '--force' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotSame('existing-content', $this->files->get($privateOutput));
    }

    public function test_it_generates_a_signing_rotation_fragment_with_staged_guidance(): void
    {
        $privateOutput = $this->temporaryDirectory.'/signing.jwks.json';
        $tester = $this->executeCommand([
            '--keys' => 'signing',
            '--private-output' => $privateOutput,
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $payload = $this->decodeJsonFile($privateOutput);
        $output = $this->normaliseOutput($tester->getDisplay());

        $this->assertCount(1, $payload['keys']);
        $this->assertSame('sig', $payload['keys'][0]['use']);
        $this->assertStringContainsString('This is a JWKS fragment.', $output);
        $this->assertStringContainsString('Wait at least one hour', $output);
        $this->assertStringContainsString('Signing rotation:', $output);
    }

    public function test_it_generates_an_encryption_rotation_fragment_with_staged_guidance(): void
    {
        $privateOutput = $this->temporaryDirectory.'/encryption.jwks.json';
        $tester = $this->executeCommand([
            '--keys' => 'encryption',
            '--private-output' => $privateOutput,
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $payload = $this->decodeJsonFile($privateOutput);
        $output = $this->normaliseOutput($tester->getDisplay());

        $this->assertCount(1, $payload['keys']);
        $this->assertSame('enc', $payload['keys'][0]['use']);
        $this->assertStringNotContainsString('MYINFO_V5_CHOSEN_JWKS_SIG_KID=', $output);
        $this->assertStringContainsString('This is a JWKS fragment.', $output);
        $this->assertStringContainsString('Wait at least one hour', $output);
        $this->assertStringContainsString('Encryption rotation:', $output);
    }

    public function test_it_can_write_the_public_jwks_to_a_separate_file(): void
    {
        $privateOutput = $this->temporaryDirectory.'/private.jwks.json';
        $publicOutput = $this->temporaryDirectory.'/public.jwks.json';
        $tester = $this->executeCommand([
            '--private-output' => $privateOutput,
            '--public-output' => $publicOutput,
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $privatePayload = $this->decodeJsonFile($privateOutput);
        $publicPayload = $this->decodeJsonFile($publicOutput);

        $this->assertCount(2, $publicPayload['keys']);
        $this->assertArrayHasKey('d', $privatePayload['keys'][0]);
        $this->assertArrayNotHasKey('d', $publicPayload['keys'][0]);
        $this->assertStringNotContainsString('MYINFO_V5_PUBLIC_JWKS=', $tester->getDisplay());
        $this->assertStringContainsString("Public JWKS written to [{$publicOutput}].", $tester->getDisplay());
    }

    public function test_it_accepts_supported_algorithms_and_curves_as_options(): void
    {
        $privateOutput = $this->temporaryDirectory.'/alternative-algorithms.jwks.json';
        $tester = $this->executeCommand([
            '--private-output' => $privateOutput,
            '--signing-alg' => 'ES384',
            '--encryption-alg' => 'ECDH-ES+A192KW',
            '--encryption-curve' => 'P-384',
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $keys = array_column($this->decodeJsonFile($privateOutput)['keys'], null, 'use');

        $this->assertSame('ES384', $keys['sig']['alg']);
        $this->assertSame('P-384', $keys['sig']['crv']);
        $this->assertSame('ECDH-ES+A192KW', $keys['enc']['alg']);
        $this->assertSame('P-384', $keys['enc']['crv']);
    }

    public function test_it_guides_the_user_through_supported_algorithm_choices(): void
    {
        $privateOutput = $this->temporaryDirectory.'/guided.jwks.json';
        $tester = $this->executeCommand(
            [
                '--configure' => true,
                '--private-output' => $privateOutput,
            ],
            ['2', '2', '2', 'yes']
        );

        $this->assertSame(0, $tester->getStatusCode());

        $keys = array_column($this->decodeJsonFile($privateOutput)['keys'], null, 'use');
        $output = $this->normaliseOutput($tester->getDisplay());

        $this->assertSame('ES512', $keys['sig']['alg']);
        $this->assertSame('P-521', $keys['sig']['crv']);
        $this->assertSame('ECDH-ES+A256KW', $keys['enc']['alg']);
        $this->assertSame('P-521', $keys['enc']['crv']);
        $this->assertStringContainsString('Choose the client-assertion signing algorithm', $output);
        $this->assertStringContainsString('Choose the JWE key-encryption algorithm', $output);
        $this->assertStringContainsString('Choose the encryption-key curve', $output);
        $this->assertStringContainsString('Signing: ES512 / P-521', $output);
        $this->assertStringContainsString('Encryption: ECDH-ES+A256KW / P-521', $output);
    }

    public function test_it_rejects_unsupported_algorithms_and_curves(): void
    {
        $invalidOptions = [
            [
                '--signing-alg' => 'RS256',
                'message' => 'The --signing-alg option must be one of: ES256, ES384, ES512.',
            ],
            [
                '--encryption-alg' => 'RSA-OAEP',
                'message' => 'The --encryption-alg option must be one of: ECDH-ES+A128KW, ECDH-ES+A192KW, ECDH-ES+A256KW.',
            ],
            [
                '--encryption-curve' => 'secp256k1',
                'message' => 'The --encryption-curve option must be one of: P-256, P-384, P-521.',
            ],
        ];

        foreach ($invalidOptions as $invalidOption) {
            $message = $invalidOption['message'];
            unset($invalidOption['message']);

            $tester = $this->executeCommand([
                ...$invalidOption,
                '--show-private' => true,
            ]);

            $this->assertSame(2, $tester->getStatusCode());
            $this->assertStringContainsString($message, $tester->getDisplay());
        }
    }

    public function test_it_rejects_an_unknown_key_selection(): void
    {
        $tester = $this->executeCommand([
            '--keys' => 'unknown',
            '--show-private' => true,
        ]);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString(
            'The --keys option must be one of: both, signing, encryption.',
            $tester->getDisplay()
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $interactiveInputs
     */
    private function executeCommand(array $input = [], array $interactiveInputs = []): CommandTester
    {
        $command = $this->app->make(GenerateJwkSetCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        if ($interactiveInputs !== []) {
            $tester->setInputs($interactiveInputs);
        }

        $tester->execute($input);

        return $tester;
    }

    private function normaliseOutput(string $output): string
    {
        return str_replace("\r\n", "\n", $output);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        return json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJsonEnvironmentValue(string $output, string $name): array
    {
        $matched = preg_match(
            '/^'.preg_quote($name, '/').'=\'(.+)\'$/m',
            $output,
            $matches
        );

        $this->assertSame(1, $matched, "Expected {$name} in command output.");

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
