<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\JwkSetFileStore;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\JwkSetRotation;

final class RotateJwkSetCommand extends Command
{
    protected $signature = 'myinfo:rotate-jwks
        {--stage= : Rotation stage: prepare or finalize}
        {--role= : Key role: signing or encryption}
        {--replace-kid= : Existing key id to replace or retire}
        {--private-input= : Path to the current private JWKS}
        {--public-input= : Path to the current public JWKS}
        {--private-output= : New path for the complete private JWKS}
        {--public-output= : New path for the complete public JWKS}
        {--signing-alg= : Optional replacement signing algorithm}
        {--encryption-alg= : Optional replacement encryption algorithm}
        {--encryption-curve= : Optional replacement encryption curve}
        {--active-signing-kid= : Active signing key id required for signing finalization}
        {--confirm-cache-expired : Confirm that the one-hour Singpass JWKS cache window has elapsed}';

    protected $description = 'Prepare or finalize a validated zero-downtime Singpass JWKS rotation';

    public function handle(JwkSetFileStore $fileStore, JwkSetRotation $rotation): int
    {
        try {
            $stage = $this->requiredOption('stage');
            $role = $this->requiredOption('role');

            if (! in_array($stage, ['prepare', 'finalize'], true)) {
                throw new RuntimeException('The --stage option must be one of: prepare, finalize.');
            }

            if (! in_array($role, ['signing', 'encryption'], true)) {
                throw new RuntimeException('The --role option must be one of: signing, encryption.');
            }

            $replaceKid = $this->requiredOption('replace-kid');
            $privateInput = $this->requiredOption('private-input');
            $publicInput = $this->requiredOption('public-input');
            $privateOutput = $this->requiredOption('private-output');
            $publicOutput = $this->requiredOption('public-output');
            $fileStore->assertDistinctRotationPaths(
                $privateOutput,
                $publicOutput,
                [$privateInput, $publicInput]
            );
            $fileStore->assertOutputPath($privateOutput);
            $fileStore->assertOutputPath($publicOutput);
            $privateJwks = $fileStore->read($privateInput);
            $publicJwks = $fileStore->read($publicInput);

            if ($stage === 'prepare') {
                $result = $role === 'signing'
                    ? $rotation->prepareSigning(
                        $privateJwks,
                        $publicJwks,
                        $replaceKid,
                        $this->stringOption('signing-alg')
                    )
                    : $rotation->prepareEncryption(
                        $privateJwks,
                        $publicJwks,
                        $replaceKid,
                        $this->stringOption('encryption-alg'),
                        $this->stringOption('encryption-curve')
                    );
            } elseif ($role === 'signing') {
                $result = $rotation->finalizeSigning(
                    $privateJwks,
                    $publicJwks,
                    $replaceKid,
                    $this->requiredOption('active-signing-kid')
                );
            } else {
                $result = $rotation->finalizeEncryption($privateJwks, $publicJwks, $replaceKid);
            }

            if ($stage === 'finalize' && ! $this->cacheExpiryConfirmed()) {
                throw new RuntimeException(
                    'JWKS finalization was not confirmed; wait for the one-hour Singpass cache window and try again.'
                );
            }

            $fileStore->writePair(
                $privateOutput,
                $publicOutput,
                $result['private'],
                $result['public'],
                [$privateInput, $publicInput]
            );

            $this->info("Private JWKS written to [{$privateOutput}] with owner-only permissions.");
            $this->info("Public JWKS written to [{$publicOutput}].");

            if ($stage === 'prepare') {
                $newKid = $result['newKid'];
                $this->info("New {$role} kid: {$newKid}");

                if ($role === 'signing') {
                    $this->line("Keep the current signing selection on the old key [{$replaceKid}] initially.");
                    $this->line(
                        "Next action: publish the public set, wait at least one hour, then select the new kid [{$newKid}] and finalize later."
                    );
                } else {
                    $this->line(
                        'Next action: deploy the private overlap, publish the new public set, wait at least one hour, '
                        .'then finalize removal of the old private key.'
                    );
                }
            } else {
                $this->info("Retired {$role} kid: {$replaceKid}");

                if ($role === 'signing') {
                    $this->line('Active public signing kid: '.$result['activeKid']);
                } else {
                    $this->line('Active public encryption kids: '.implode(', ', $this->publicKidsForRole($result['public'], 'enc')));
                }
            }

            $this->comment('These files were created locally; no deployment, configuration, publication, or Singpass update was performed.');

            return self::SUCCESS;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (Throwable) {
            $this->error('Unable to complete the JWKS rotation safely.');

            return self::FAILURE;
        }
    }

    private function cacheExpiryConfirmed(): bool
    {
        if ($this->option('confirm-cache-expired') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        return $this->confirm(
            'Has at least one hour elapsed since the staged public JWKS was published (the Singpass cache window)?',
            false
        );
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
     * @param array<string, mixed> $jwks
     * @return list<string>
     */
    private function publicKidsForRole(array $jwks, string $role): array
    {
        $keys = $jwks['keys'] ?? [];
        $kids = [];

        if (! is_array($keys)) {
            return [];
        }

        foreach ($keys as $key) {
            if (is_array($key) && ($key['use'] ?? null) === $role && isset($key['kid']) && is_string($key['kid'])) {
                $kids[] = $key['kid'];
            }
        }

        return $kids;
    }
}
