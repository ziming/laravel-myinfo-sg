<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5;

use InvalidArgumentException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;
use Saloon\Http\SoloRequest;
use Saloon\Traits\Plugins\HasTimeout;

abstract class MyinfoV5Request extends SoloRequest
{
    use HasTimeout;

    protected float $connectTimeout;

    protected float $requestTimeout;

    private int $safeReadMaxAttempts;

    private int $safeReadRetryDelayMilliseconds;

    public function __construct()
    {
        $this->connectTimeout = $this->positiveFiniteFloat('connect_timeout_seconds');
        $this->requestTimeout = $this->positiveFiniteFloat('request_timeout_seconds');
        $this->safeReadMaxAttempts = $this->boundedInteger('safe_read_max_attempts', 1, 3);
        $this->safeReadRetryDelayMilliseconds = $this->boundedInteger(
            'safe_read_retry_delay_milliseconds',
            0,
            5000,
        );
    }

    protected function enableSafeReadRetries(): void
    {
        $this->tries = $this->safeReadMaxAttempts;
        $this->retryInterval = $this->safeReadRetryDelayMilliseconds;
        $this->useExponentialBackoff = false;
        $this->throwOnMaxTries = false;
    }

    /** @internal */
    public function safeReadMaxAttempts(): int
    {
        return $this->safeReadMaxAttempts;
    }

    /** @internal */
    public function safeReadRetryDelayMilliseconds(): int
    {
        return $this->safeReadRetryDelayMilliseconds;
    }

    public function handleRetry(
        FatalRequestException|RequestException $exception,
        Request $request,
    ): bool {
        if ($exception instanceof FatalRequestException) {
            return true;
        }

        return in_array($exception->getResponse()->status(), [429, 502, 503, 504], true);
    }

    private function positiveFiniteFloat(string $key): float
    {
        $value = config("laravel-myinfo-sg-v5.{$key}");

        if (
            is_bool($value)
            || (! is_int($value) && ! is_float($value) && ! is_string($value))
            || ! is_numeric($value)
        ) {
            throw $this->invalidConfiguration($key);
        }

        $normalized = (float) $value;

        if (! is_finite($normalized) || $normalized <= 0) {
            throw $this->invalidConfiguration($key);
        }

        return $normalized;
    }

    private function boundedInteger(string $key, int $minimum, int $maximum): int
    {
        $value = config("laravel-myinfo-sg-v5.{$key}");

        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/D', $value) === 1) {
            $normalized = (int) $value;
        } else {
            throw $this->invalidConfiguration($key);
        }

        if ($normalized < $minimum || $normalized > $maximum) {
            throw $this->invalidConfiguration($key);
        }

        return $normalized;
    }

    private function invalidConfiguration(string $key): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "MyInfo V5 transport configuration [{$key}] is invalid.",
        );
    }
}
