<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV5;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use RuntimeException;
use Ziming\LaravelMyinfoSg\Data\MyinfoV5\AuthorizationTransaction;

final readonly class AuthorizationTransactionStore
{
    public function __construct(private Session $session)
    {
    }

    public function put(AuthorizationTransaction $transaction): void
    {
        $records = $this->prunedRecords();
        $records[$this->keyForState($transaction->state)] = $transaction->toSessionRecord();

        $this->write($records);
    }

    public function peek(string $state): ?AuthorizationTransaction
    {
        $records = $this->prunedRecords();
        $this->write($records);
        $record = $records[$this->keyForState($state)] ?? null;

        if (! is_array($record)) {
            return null;
        }

        return AuthorizationTransaction::fromSessionRecord($record);
    }

    public function pull(string $state): ?AuthorizationTransaction
    {
        $records = $this->prunedRecords();
        $key = $this->keyForState($state);
        $record = $records[$key] ?? null;
        unset($records[$key]);
        $this->write($records);

        if (! is_array($record)) {
            return null;
        }

        return AuthorizationTransaction::fromSessionRecord($record);
    }

    public function prune(): void
    {
        $this->write($this->prunedRecords());
    }

    /**
     * @return array<string, mixed>
     */
    private function prunedRecords(): array
    {
        $records = $this->session->get($this->sessionKey(), []);

        if (! is_array($records)) {
            return [];
        }

        $expiresBeforeOrAt = CarbonImmutable::now()->timestamp - $this->ttlSeconds();

        return array_filter(
            $records,
            static fn (mixed $record): bool => is_array($record)
                && is_int($record['created_at'] ?? null)
                && $record['created_at'] > $expiresBeforeOrAt,
        );
    }

    /**
     * @param array<string, mixed> $records
     */
    private function write(array $records): void
    {
        $this->session->put($this->sessionKey(), $records);
    }

    private function keyForState(string $state): string
    {
        return hash('sha256', $state);
    }

    private function sessionKey(): string
    {
        $sessionKey = config('laravel-myinfo-sg-v5.transaction_session_key');

        if (! is_string($sessionKey) || $sessionKey === '') {
            throw new RuntimeException('MyInfo V5 transaction session key is not configured.');
        }

        return $sessionKey;
    }

    private function ttlSeconds(): int
    {
        $ttl = config('laravel-myinfo-sg-v5.transaction_ttl_seconds');

        if (! is_int($ttl) || $ttl < 1) {
            throw new RuntimeException('MyInfo V5 transaction TTL must be a positive integer.');
        }

        return $ttl;
    }
}
