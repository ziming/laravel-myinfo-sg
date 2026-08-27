<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

final class JwkSetFileStore
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly JwkSetValidator $validator
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        $this->assertExplicitPath($path);

        if (is_link($path)) {
            throw new RuntimeException("Refusing to read JWKS from symbolic link [{$path}].");
        }

        if (! $this->files->exists($path)) {
            throw new RuntimeException("JWKS input file [{$path}] does not exist.");
        }

        if ($this->files->isDirectory($path)) {
            throw new RuntimeException("JWKS input path [{$path}] is a directory.");
        }

        if (! is_file($path) || ! is_readable($path) || (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0444) === 0)) {
            throw new RuntimeException("JWKS input file [{$path}] cannot be read.");
        }

        try {
            $json = $this->files->get($path);
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

            if (! $object instanceof stdClass) {
                throw new RuntimeException("JWKS input file [{$path}] must contain a JSON object.");
            }

            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("JWKS input file [{$path}] does not contain valid JSON.");
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException("JWKS input file [{$path}] cannot be read.");
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("JWKS input file [{$path}] must contain a JSON object.");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('Unable to encode the JWKS payload.');
        }
    }

    public function assertOutputPath(string $path, bool $overwrite = false, bool $suggestForce = false): void
    {
        $this->assertExplicitPath($path);

        if (is_link($path)) {
            throw new RuntimeException("Refusing to write JWKS to symbolic link [{$path}].");
        }

        if ($this->files->isDirectory($path)) {
            throw new RuntimeException("JWKS output path [{$path}] is a directory.");
        }

        if ($this->files->exists($path) && ! $overwrite) {
            $hint = $suggestForce ? ' Use --force to overwrite it.' : '';

            throw new RuntimeException("JWKS output file [{$path}] already exists.{$hint}");
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function write(string $path, array $payload, bool $private, bool $overwrite = false): void
    {
        $this->assertOutputPath($path, $overwrite);
        $mode = $private ? 0600 : 0644;
        $directoryMode = $private ? 0700 : 0755;

        $this->files->ensureDirectoryExists(dirname($path), $directoryMode);
        $this->files->replace($path, $this->encode($payload).PHP_EOL, $mode);

        if (! $this->files->exists($path)) {
            throw new RuntimeException("Unable to write JWKS output file [{$path}].");
        }

        $this->securePermissions($path, $mode);
    }

    /**
     * @param array<string, mixed> $privatePayload
     * @param array<string, mixed> $publicPayload
     * @param list<string> $inputPaths
     */
    public function writePair(
        string $privatePath,
        string $publicPath,
        array $privatePayload,
        array $publicPayload,
        array $inputPaths
    ): void {
        $this->assertDistinctRotationPaths($privatePath, $publicPath, $inputPaths);
        $this->assertOutputPath($privatePath);
        $this->assertOutputPath($publicPath);

        $this->files->ensureDirectoryExists(dirname($privatePath), 0700);
        $this->files->ensureDirectoryExists(dirname($publicPath), 0755);

        $privateTemporaryPath = $this->temporarySibling($privatePath);
        $publicTemporaryPath = null;
        $publishedPaths = [];

        try {
            $publicTemporaryPath = $this->temporarySibling($publicPath);
            $this->writeTemporary($privateTemporaryPath, $privatePayload, 0600);
            $this->writeTemporary($publicTemporaryPath, $publicPayload, 0644);
            $this->validator->validatePair(
                $this->read($publicTemporaryPath),
                $this->read($privateTemporaryPath)
            );

            // Re-check immediately before publication so an existing destination is never replaced.
            $this->assertOutputPath($privatePath);
            $this->assertOutputPath($publicPath);

            if (! $this->files->move($privateTemporaryPath, $privatePath)) {
                throw new RuntimeException("Unable to publish JWKS output file [{$privatePath}].");
            }

            $publishedPaths[] = $privatePath;

            if (! $this->files->move($publicTemporaryPath, $publicPath)) {
                throw new RuntimeException("Unable to publish JWKS output file [{$publicPath}].");
            }

            $publishedPaths[] = $publicPath;
            $this->securePermissions($privatePath, 0600);
            $this->securePermissions($publicPath, 0644);
        } catch (Throwable $exception) {
            foreach ($publishedPaths as $publishedPath) {
                if ($this->files->exists($publishedPath) && ! is_link($publishedPath)) {
                    $this->files->delete($publishedPath);
                }
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Unable to write the JWKS output files.');
        } finally {
            foreach ([$privateTemporaryPath, $publicTemporaryPath] as $temporaryPath) {
                if (is_string($temporaryPath) && $this->files->exists($temporaryPath) && ! is_link($temporaryPath)) {
                    $this->files->delete($temporaryPath);
                }
            }
        }
    }

    /**
     * @param list<string> $inputPaths
     */
    public function assertDistinctRotationPaths(string $privatePath, string $publicPath, array $inputPaths): void
    {
        $outputPaths = [
            $this->normalisePath($privatePath),
            $this->normalisePath($publicPath),
        ];

        if ($outputPaths[0] === $outputPaths[1]) {
            throw new RuntimeException('Private and public JWKS output paths must be different.');
        }

        $normalisedInputs = array_map($this->normalisePath(...), $inputPaths);

        foreach ($outputPaths as $outputPath) {
            if (in_array($outputPath, $normalisedInputs, true)) {
                throw new RuntimeException('JWKS rotation output paths must be different from every input path.');
            }
        }
    }

    private function assertExplicitPath(string $path): void
    {
        if (trim($path) === '' || str_contains($path, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1) {
            throw new RuntimeException('A valid explicit filesystem path is required for each JWKS file.');
        }
    }

    private function temporarySibling(string $path): string
    {
        $temporaryPath = tempnam(dirname($path), '.'.basename($path).'.tmp-');

        if ($temporaryPath === false) {
            throw new RuntimeException("Unable to prepare JWKS output file [{$path}].");
        }

        return $temporaryPath;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeTemporary(string $path, array $payload, int $mode): void
    {
        if ($this->files->put($path, $this->encode($payload).PHP_EOL, true) === false) {
            throw new RuntimeException('Unable to prepare a JWKS output file.');
        }

        $this->securePermissions($path, $mode);
    }

    private function securePermissions(string $path, int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '/' && $this->files->chmod($path, $mode) === false) {
            throw new RuntimeException("Unable to set safe permissions on JWKS output file [{$path}].");
        }
    }

    private function normalisePath(string $path): string
    {
        $this->assertExplicitPath($path);
        $realPath = realpath($path);

        if ($realPath !== false) {
            return $realPath;
        }

        $directory = realpath(dirname($path));

        if ($directory !== false) {
            return $directory.DIRECTORY_SEPARATOR.basename($path);
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : getcwd().DIRECTORY_SEPARATOR.$path;
    }
}
