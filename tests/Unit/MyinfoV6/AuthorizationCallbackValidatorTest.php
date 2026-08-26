<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Jose\Component\KeyManagement\JWKFactory;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\AuthorizationTransaction;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\AuthorizationResponseException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidAuthorizationCallbackException;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationCallbackValidator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationTransactionStore;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class AuthorizationCallbackValidatorTest extends TestCase
{
    private const ISSUER = 'https://issuer.example/fapi';

    private AuthorizationTransactionStore $transactions;
    private AuthorizationCallbackValidator $validator;
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
        $this->transactions = new AuthorizationTransactionStore(session()->driver());
        $this->validator = new AuthorizationCallbackValidator($this->transactions);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_valid_callback_returns_code_and_consumed_transaction(): void
    {
        $transaction = $this->putTransaction('valid-state');

        $callback = $this->validator->validate($this->authorizationRequest([
            'state' => 'valid-state',
            'iss' => self::ISSUER,
            'code' => 'authorization-code',
        ]));

        $this->assertSame('authorization-code', $callback->code);
        $this->assertSame($transaction->toSessionRecord(), $callback->transaction->toSessionRecord());
        $this->assertNull($this->transactions->peek('valid-state'));
    }

    public function test_provider_error_is_sanitized_and_consumes_transaction(): void
    {
        $this->putTransaction('error-state');
        $description = '<script>remote description</script>';

        try {
            $this->validator->validate($this->authorizationRequest([
                'state' => 'error-state',
                'iss' => self::ISSUER,
                'error' => 'temporarily_unavailable',
                'error_description' => $description,
            ]));
            $this->fail('Expected the provider error to fail validation.');
        } catch (AuthorizationResponseException $exception) {
            $this->assertSame('temporarily_unavailable', $exception->errorCode);
            $this->assertStringNotContainsString($description, $exception->getMessage());
            $this->assertStringNotContainsString('temporarily_unavailable', $exception->getMessage());
        }

        $this->assertNull($this->transactions->peek('error-state'));
    }

    public function test_unsafe_provider_error_code_is_replaced(): void
    {
        $this->putTransaction('unsafe-error-state');

        try {
            $this->validator->validate($this->authorizationRequest([
                'state' => 'unsafe-error-state',
                'iss' => self::ISSUER,
                'error' => '<unsafe>',
            ]));
            $this->fail('Expected the provider error to fail validation.');
        } catch (AuthorizationResponseException $exception) {
            $this->assertSame('authorization_error', $exception->errorCode);
        }
    }

    public function test_missing_or_mismatched_issuer_consumes_the_identified_transaction(): void
    {
        foreach ([null, 'https://wrong-issuer.example/fapi'] as $issuer) {
            $state = $issuer === null ? 'missing-issuer' : 'wrong-issuer';
            $this->putTransaction($state);
            $parameters = ['state' => $state, 'code' => 'code'];

            if ($issuer !== null) {
                $parameters['iss'] = $issuer;
            }

            try {
                $this->validator->validate($this->authorizationRequest($parameters));
                $this->fail('Expected an invalid issuer to fail validation.');
            } catch (InvalidAuthorizationCallbackException $exception) {
                $this->assertSame('Authorization callback issuer is invalid.', $exception->getMessage());
            }

            $this->assertNull($this->transactions->peek($state));
        }
    }

    public function test_missing_invalid_and_unknown_state_do_not_consume_other_transactions(): void
    {
        $this->putTransaction('other-valid-state');

        foreach ([null, 'contains spaces', str_repeat('a', 256), 'unknown-state'] as $state) {
            $parameters = ['iss' => self::ISSUER, 'code' => 'code'];

            if ($state !== null) {
                $parameters['state'] = $state;
            }

            try {
                $this->validator->validate($this->authorizationRequest($parameters));
                $this->fail('Expected an invalid state to fail validation.');
            } catch (InvalidAuthorizationCallbackException) {
                $this->assertNotNull($this->transactions->peek('other-valid-state'));
            }
        }
    }

    public function test_replayed_and_expired_state_fail(): void
    {
        $this->putTransaction('one-time-state');
        $request = $this->authorizationRequest([
            'state' => 'one-time-state',
            'iss' => self::ISSUER,
            'code' => 'code',
        ]);
        $this->validator->validate($request);

        $this->expectException(InvalidAuthorizationCallbackException::class);
        $this->validator->validate($request);
    }

    public function test_expired_state_fails(): void
    {
        $this->putTransaction('expired-state');
        CarbonImmutable::setTestNow('2026-08-27 10:10:00');

        $this->expectException(InvalidAuthorizationCallbackException::class);
        $this->validator->validate($this->authorizationRequest([
            'state' => 'expired-state',
            'iss' => self::ISSUER,
            'code' => 'code',
        ]));
    }

    public function test_missing_code_and_error_code_combination_fail_after_consuming_state(): void
    {
        foreach ([
            'missing-code' => [],
            'conflicting-response' => ['code' => 'code', 'error' => 'server_error'],
        ] as $state => $responseParameters) {
            $this->putTransaction($state);

            try {
                $this->validator->validate($this->authorizationRequest([
                    'state' => $state,
                    'iss' => self::ISSUER,
                    ...$responseParameters,
                ]));
                $this->fail('Expected invalid callback parameters to fail validation.');
            } catch (InvalidAuthorizationCallbackException) {
                $this->assertNull($this->transactions->peek($state));
            }
        }
    }

    public function test_concurrent_callbacks_can_be_validated_in_reverse_order(): void
    {
        $this->putTransaction('state-A', 'verifier-A');
        $this->putTransaction('state-B', 'verifier-B');

        $second = $this->validator->validate($this->authorizationRequest([
            'state' => 'state-B',
            'iss' => self::ISSUER,
            'code' => 'code-B',
        ]));
        $first = $this->validator->validate($this->authorizationRequest([
            'state' => 'state-A',
            'iss' => self::ISSUER,
            'code' => 'code-A',
        ]));

        $this->assertSame('verifier-B', $second->transaction->codeVerifier);
        $this->assertSame('verifier-A', $first->transaction->codeVerifier);
    }

    private function putTransaction(string $state, string $verifier = 'code-verifier'): AuthorizationTransaction
    {
        $transaction = new AuthorizationTransaction(
            $state,
            'nonce-'.$state,
            $verifier,
            'https://client.example/callback',
            self::ISSUER,
            $this->privateJwkJson,
            CarbonImmutable::now()->timestamp,
        );
        $this->transactions->put($transaction);

        return $transaction;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function authorizationRequest(array $parameters): Request
    {
        return Request::create('/callback/myinfo-v6', 'GET', $parameters);
    }
}
