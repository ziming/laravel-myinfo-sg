<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidIdTokenException;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\IdTokenProcessor;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\NestedJwtDecoder;
use Ziming\LaravelMyinfoSg\Tests\TestCase;
use Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6\Support\NestedTokenFactory;

class IdTokenProcessorTest extends TestCase
{
    private const string ISSUER = 'https://stg-id.singpass.gov.sg/fapi';

    private const string CLIENT_ID = 'test-client-id';

    private const string NONCE = 'transaction-nonce';

    private MockClock $clock;

    private JWK $decryptionKey;

    private JWK $signingKey;

    public function setUp(): void
    {
        parent::setUp();

        $this->clock = new MockClock('2026-08-27 10:00:00 UTC');
        $this->decryptionKey = NestedTokenFactory::encryptionKey();
        $this->signingKey = NestedTokenFactory::signingKey();
    }

    public function test_processes_a_fully_valid_id_token(): void
    {
        $claims = $this->process($this->validClaims());

        $this->assertSame('S1234567A', $claims['sub']);
        $this->assertSame(self::NONCE, $claims['nonce']);
    }

    public function test_accepts_the_oidc_audience_array_shape(): void
    {
        $claims = $this->validClaims();
        $claims['aud'] = ['another-audience', self::CLIENT_ID];

        $processed = $this->process($claims);

        $this->assertSame($claims['aud'], $processed['aud']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mandatoryClaims(): iterable
    {
        foreach (['iss', 'aud', 'exp', 'iat', 'nonce', 'sub'] as $claim) {
            yield $claim => [$claim];
        }
    }

    #[DataProvider('mandatoryClaims')]
    public function test_rejects_each_missing_mandatory_claim(string $claim): void
    {
        $claims = $this->validClaims();
        unset($claims[$claim]);

        $this->assertInvalid($claims);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function malformedClaims(): iterable
    {
        yield 'issuer must be a string' => ['iss', 123];
        yield 'audience must be a string or list of strings' => ['aud', [self::CLIENT_ID, 123]];
        yield 'expiry must be numeric' => ['exp', '1787800000'];
        yield 'issued-at must be numeric' => ['iat', '1787800000'];
        yield 'nonce must be a string' => ['nonce', ['transaction-nonce']];
        yield 'subject must be a string' => ['sub', ['S1234567A']];
    }

    #[DataProvider('malformedClaims')]
    public function test_rejects_malformed_claim_types(string $claim, mixed $value): void
    {
        $claims = $this->validClaims();
        $claims[$claim] = $value;

        $this->assertInvalid($claims);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidClaimValues(): iterable
    {
        yield 'wrong issuer' => ['iss', 'https://attacker.example'];
        yield 'wrong audience' => ['aud', 'another-client'];
        yield 'wrong nonce' => ['nonce', 'another-nonce'];
        yield 'empty subject' => ['sub', ''];
        yield 'whitespace subject' => ['sub', '   '];
    }

    #[DataProvider('invalidClaimValues')]
    public function test_rejects_invalid_claim_values(string $claim, mixed $value): void
    {
        $claims = $this->validClaims();
        $claims[$claim] = $value;

        $this->assertInvalid($claims);
    }

    public function test_rejects_an_expired_token_beyond_the_two_second_skew(): void
    {
        $claims = $this->validClaims();
        $claims['exp'] = $this->clock->now()->getTimestamp() - 3;

        $this->assertInvalid($claims);
    }

    public function test_rejects_an_issued_at_time_beyond_the_two_second_skew(): void
    {
        $claims = $this->validClaims();
        $claims['iat'] = $this->clock->now()->getTimestamp() + 3;

        $this->assertInvalid($claims);
    }

    public function test_allows_time_claims_within_the_two_second_skew(): void
    {
        $claims = $this->validClaims();
        $claims['exp'] = $this->clock->now()->getTimestamp() - 1;
        $claims['iat'] = $this->clock->now()->getTimestamp() + 2;

        $processed = $this->process($claims);

        $this->assertSame($claims['exp'], $processed['exp']);
        $this->assertSame($claims['iat'], $processed['iat']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validClaims(): array
    {
        $now = $this->clock->now()->getTimestamp();

        return [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => self::NONCE,
            'sub' => 'S1234567A',
        ];
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private function process(array $claims): array
    {
        $token = NestedTokenFactory::idToken(
            $claims,
            $this->decryptionKey,
            $this->signingKey,
        );

        return (new IdTokenProcessor(new NestedJwtDecoder, $this->clock))->process(
            $token,
            new JWKSet([$this->decryptionKey]),
            new JWKSet([$this->signingKey->toPublic()]),
            self::ISSUER,
            self::CLIENT_ID,
            self::NONCE,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertInvalid(array $claims): void
    {
        $token = NestedTokenFactory::idToken(
            $claims,
            $this->decryptionKey,
            $this->signingKey,
        );

        try {
            (new IdTokenProcessor(new NestedJwtDecoder, $this->clock))->process(
                $token,
                new JWKSet([$this->decryptionKey]),
                new JWKSet([$this->signingKey->toPublic()]),
                self::ISSUER,
                self::CLIENT_ID,
                self::NONCE,
            );
            $this->fail('Expected invalid ID token claims to fail.');
        } catch (InvalidIdTokenException $exception) {
            $this->assertSame('The ID token is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString($token, $exception->getMessage());
            $this->assertStringNotContainsString((string) $this->decryptionKey->get('d'), $exception->getMessage());
        }
    }
}
