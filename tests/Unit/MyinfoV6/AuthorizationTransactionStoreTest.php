<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use InvalidArgumentException;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\AuthorizationTransaction;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationTransactionStore;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class AuthorizationTransactionStoreTest extends TestCase
{
    private string $privateJwkJson;

    public function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-27 10:00:00');
        config()->set('laravel-myinfo-sg-v6.transaction_session_key', 'test_myinfo_transactions');
        config()->set('laravel-myinfo-sg-v6.transaction_ttl_seconds', 600);

        $this->privateJwkJson = json_encode(
            JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig']),
            JSON_THROW_ON_ERROR,
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_transactions_round_trip_under_state_digests_without_overwriting_each_other(): void
    {
        $store = $this->store();
        $first = $this->transaction('state-A', 'verifier-A');
        $second = $this->transaction('state-B', 'verifier-B');

        $store->put($first);
        $store->put($second);

        $this->assertSame('verifier-A', $store->peek('state-A')?->codeVerifier);
        $this->assertSame('verifier-B', $store->peek('state-B')?->codeVerifier);

        $records = session()->get('test_myinfo_transactions');
        $this->assertIsArray($records);
        $this->assertArrayHasKey(hash('sha256', 'state-A'), $records);
        $this->assertArrayHasKey(hash('sha256', 'state-B'), $records);
        $this->assertArrayNotHasKey('state-A', $records);

        $restored = $store->peek('state-A');
        $this->assertNotNull($restored);
        $this->assertSame($first->toSessionRecord(), $restored->toSessionRecord());
        $this->assertInstanceOf(JWK::class, $restored->dpopPrivateJwk());
        $this->assertTrue($restored->dpopPrivateJwk()->has('d'));
    }

    public function test_pull_is_one_time_and_does_not_consume_another_transaction(): void
    {
        $store = $this->store();
        $store->put($this->transaction('state-A', 'verifier-A'));
        $store->put($this->transaction('state-B', 'verifier-B'));

        $this->assertSame('verifier-A', $store->pull('state-A')?->codeVerifier);
        $this->assertNull($store->pull('state-A'));
        $this->assertSame('verifier-B', $store->peek('state-B')?->codeVerifier);
        $this->assertNull($store->peek('missing-state'));
    }

    public function test_expired_transactions_are_pruned(): void
    {
        $store = $this->store();
        $store->put($this->transaction('expired-state', 'verifier'));

        CarbonImmutable::setTestNow('2026-08-27 10:10:00');

        $store->prune();

        $this->assertSame([], session()->get('test_myinfo_transactions'));
        $this->assertNull($store->peek('expired-state'));
    }

    public function test_transactions_cannot_be_read_from_another_session(): void
    {
        $handler = new ArraySessionHandler(120);
        $firstSession = new Store('test', $handler, str_repeat('a', 40));
        $firstSession->start();
        (new AuthorizationTransactionStore($firstSession))->put(
            $this->transaction('session-bound-state', 'verifier'),
        );
        $firstSession->save();

        $secondSession = new Store('test', $handler, str_repeat('b', 40));
        $secondSession->start();

        $this->assertNull(
            (new AuthorizationTransactionStore($secondSession))->peek('session-bound-state'),
        );
    }

    public function test_invalid_private_material_is_not_exposed_by_hydration_errors(): void
    {
        $privateMarker = 'private-material-must-not-leak';

        try {
            new AuthorizationTransaction(
                'state',
                'nonce',
                'verifier',
                'https://client.example/callback',
                'https://issuer.example/fapi',
                "{not-json-{$privateMarker}}",
                CarbonImmutable::now()->timestamp,
            );
            $this->fail('Expected invalid DPoP private material to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringNotContainsString($privateMarker, $exception->getMessage());
            $this->assertStringNotContainsString($privateMarker, (string) $exception);
        }
    }

    private function store(): AuthorizationTransactionStore
    {
        return new AuthorizationTransactionStore(session()->driver());
    }

    private function transaction(string $state, string $codeVerifier): AuthorizationTransaction
    {
        return new AuthorizationTransaction(
            $state,
            'nonce-'.$state,
            $codeVerifier,
            'https://client.example/callback',
            'https://issuer.example/fapi',
            $this->privateJwkJson,
            CarbonImmutable::now()->timestamp,
        );
    }
}
