<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\Console\Tester\CommandTester;
use Ziming\LaravelMyinfoSg\Console\Commands\ValidateJwkSetCommand;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetGenerator;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class ValidateJwkSetCommandTest extends TestCase
{
    private Filesystem $files;

    private string $temporaryDirectory;

    private string $privatePath;

    private string $publicPath;

    /** @var array{keys: list<array<string, mixed>>} */
    private array $privateJwks;

    /** @var array{keys: list<array<string, mixed>>} */
    private array $publicJwks;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->temporaryDirectory = sys_get_temp_dir().'/laravel-myinfo-validation-'.Str::uuid();
        $this->files->makeDirectory($this->temporaryDirectory, 0700, true);
        $this->privatePath = $this->temporaryDirectory.'/private.json';
        $this->publicPath = $this->temporaryDirectory.'/public.json';
        $generator = new JwkSetGenerator;
        $privateKeys = [$generator->signingKey(), $generator->encryptionKey()];
        $this->privateJwks = ['keys' => $privateKeys];
        $this->publicJwks = ['keys' => array_map($generator->publicKey(...), $privateKeys)];
        $this->writePair($this->privateJwks, $this->publicJwks);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_validates_a_pair_and_an_optional_selected_signing_key(): void
    {
        $signingKid = $this->privateJwks['keys'][0]['kid'];
        $tester = $this->executeCommand([
            '--private' => $this->privatePath,
            '--public' => $this->publicPath,
            '--signing-kid' => $signingKid,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('JWKS pair is valid.', $tester->getDisplay());
        $this->assertStringContainsString("Selected signing kid [{$signingKid}] is valid", $tester->getDisplay());
        $this->assertStringContainsString('Private keys: 2 (signing: 1; encryption: 1).', $tester->getDisplay());
        $this->assertStringNotContainsString('"d"', $tester->getDisplay());
        $this->assertStringNotContainsString((string) $this->privateJwks['keys'][0]['d'], $tester->getDisplay());
    }

    public function test_it_rejects_malformed_json_without_echoing_private_input(): void
    {
        $sentinel = 'never-print-this-private-sentinel';
        $this->files->put($this->privatePath, '{"keys":[{"d":"'.$sentinel.'"}');

        $tester = $this->executeCommand([
            '--private' => $this->privatePath,
            '--public' => $this->publicPath,
        ]);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('does not contain valid JSON', $tester->getDisplay());
        $this->assertStringNotContainsString($sentinel, $tester->getDisplay());
    }

    public function test_it_rejects_invalid_pairs_and_public_private_material_safely(): void
    {
        $sentinel = 'never-print-this-private-coordinate';
        $cases = [];

        $publicPrivateMaterial = $this->publicJwks;
        $publicPrivateMaterial['keys'][0]['d'] = $sentinel;
        $cases[] = [$this->privateJwks, $publicPrivateMaterial, '[d]'];

        $coordinateMismatch = $this->publicJwks;
        $coordinateMismatch['keys'][0]['x'] = 'different-public-coordinate';
        $cases[] = [$this->privateJwks, $coordinateMismatch, '[x]'];

        $duplicateKid = $this->publicJwks;
        $duplicateKid['keys'][1]['kid'] = $duplicateKid['keys'][0]['kid'];
        $cases[] = [$this->privateJwks, $duplicateKid, 'duplicate'];

        $unsupportedAlgorithm = $this->publicJwks;
        $unsupportedAlgorithm['keys'][1]['alg'] = 'RSA-OAEP';
        $cases[] = [$this->privateJwks, $unsupportedAlgorithm, '[alg]'];

        $unsupportedCurve = $this->publicJwks;
        $unsupportedCurve['keys'][1]['crv'] = 'secp256k1';
        $cases[] = [$this->privateJwks, $unsupportedCurve, '[crv]'];

        foreach ($cases as [$privateJwks, $publicJwks, $expectedMessage]) {
            $this->writePair($privateJwks, $publicJwks);
            $tester = $this->executeCommand([
                '--private' => $this->privatePath,
                '--public' => $this->publicPath,
            ]);

            $this->assertSame(2, $tester->getStatusCode());
            $this->assertStringContainsString($expectedMessage, $tester->getDisplay());
            $this->assertStringNotContainsString($sentinel, $tester->getDisplay());
        }
    }

    public function test_it_rejects_wrong_role_and_missing_selected_signing_kids(): void
    {
        foreach ([$this->privateJwks['keys'][1]['kid'], 'sig-missing'] as $kid) {
            $tester = $this->executeCommand([
                '--private' => $this->privatePath,
                '--public' => $this->publicPath,
                '--signing-kid' => $kid,
            ]);

            $this->assertSame(2, $tester->getStatusCode());
            $this->assertStringContainsString('signing kid', $tester->getDisplay());
        }
    }

    public function test_it_rejects_missing_directory_symlink_and_non_object_inputs(): void
    {
        $missingPath = $this->temporaryDirectory.'/missing.json';
        $tester = $this->executeCommand(['--private' => $missingPath, '--public' => $this->publicPath]);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('does not exist', $tester->getDisplay());

        $tester = $this->executeCommand(['--private' => $this->temporaryDirectory, '--public' => $this->publicPath]);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('is a directory', $tester->getDisplay());

        $symlinkPath = $this->temporaryDirectory.'/private-link.json';
        symlink($this->privatePath, $symlinkPath);
        $tester = $this->executeCommand(['--private' => $symlinkPath, '--public' => $this->publicPath]);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('symbolic link', $tester->getDisplay());

        $this->files->put($this->privatePath, '[]');
        $tester = $this->executeCommand(['--private' => $this->privatePath, '--public' => $this->publicPath]);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('JSON object', $tester->getDisplay());
    }

    /**
     * @param array{keys: list<array<string, mixed>>} $privateJwks
     * @param array{keys: list<array<string, mixed>>} $publicJwks
     * @throws JsonException
     */
    private function writePair(array $privateJwks, array $publicJwks): void
    {
        $this->files->put($this->privatePath, json_encode($privateJwks, JSON_THROW_ON_ERROR));
        $this->files->put($this->publicPath, json_encode($publicJwks, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeCommand(array $input): CommandTester
    {
        $command = $this->app->make(ValidateJwkSetCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
