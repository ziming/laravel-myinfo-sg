<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class MyinfoConnectorTest extends TestCase
{
    public function test_create_and_store_dpop_key_pair_persists_private_jwk_in_session(): void
    {
        $connector = new MyinfoConnector;
        $createAndStoreDpopKeyPair = \Closure::bind(
            fn (): array => $this->createAndStoreDpopKeyPair(),
            $connector,
            MyinfoConnector::class
        );

        [$privateJwk, $publicJwk] = $createAndStoreDpopKeyPair();

        $storedPrivateJwkJson = session(
            config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key')
        );
        $storedPrivateJwk = JWKFactory::createFromJsonObject($storedPrivateJwkJson);

        $this->assertInstanceOf(JWK::class, $storedPrivateJwk);
        $this->assertSame($privateJwk->get('x'), $storedPrivateJwk->get('x'));
        $this->assertSame($publicJwk->get('x'), $storedPrivateJwk->toPublic()->get('x'));
        $this->assertTrue($storedPrivateJwk->has('d'));
        $this->assertFalse($publicJwk->has('d'));
    }

    public function test_get_stored_dpop_key_pair_returns_same_key_pair(): void
    {
        $connector = new MyinfoConnector;
        $createAndStoreDpopKeyPair = \Closure::bind(
            fn (): array => $this->createAndStoreDpopKeyPair(),
            $connector,
            MyinfoConnector::class
        );
        $getStoredDpopKeyPair = \Closure::bind(
            fn (): array => $this->getStoredDpopKeyPair(),
            $connector,
            MyinfoConnector::class
        );

        [$createdPrivateJwk, $createdPublicJwk] = $createAndStoreDpopKeyPair();
        [$storedPrivateJwk, $storedPublicJwk] = $getStoredDpopKeyPair();

        $this->assertSame($createdPrivateJwk->get('x'), $storedPrivateJwk->get('x'));
        $this->assertSame($createdPrivateJwk->get('d'), $storedPrivateJwk->get('d'));
        $this->assertSame($createdPublicJwk->get('x'), $storedPublicJwk->get('x'));
        $this->assertSame($createdPublicJwk->get('y'), $storedPublicJwk->get('y'));
    }

    public function test_get_stored_dpop_key_pair_throws_when_missing_from_session(): void
    {
        $connector = new MyinfoConnector;
        $getStoredDpopKeyPair = \Closure::bind(
            fn (): array => $this->getStoredDpopKeyPair(),
            $connector,
            MyinfoConnector::class
        );

        session()->forget(config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No DPoP private key found in session');

        $getStoredDpopKeyPair();
    }
}
