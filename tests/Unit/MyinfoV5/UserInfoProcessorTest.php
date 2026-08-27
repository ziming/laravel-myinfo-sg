<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Ziming\LaravelMyinfoSg\Data\MyinfoV5\VerifiedUserInfo;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5\InvalidUserInfoException;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\NestedJwtDecoder;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\UserInfoProcessor;
use Ziming\LaravelMyinfoSg\Tests\TestCase;
use Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5\Support\NestedTokenFactory;

class UserInfoProcessorTest extends TestCase
{
    private const string ISSUER = 'https://stg-id.singpass.gov.sg/fapi';

    private const string CLIENT_ID = 'test-client-id';

    private const string SUBJECT = 'verified-subject';

    private const int NOW = 1787805600;

    private JWK $decryptionKey;

    private JWK $signingKey;

    private MockClock $clock;

    public function setUp(): void
    {
        parent::setUp();

        $this->decryptionKey = NestedTokenFactory::encryptionKey();
        $this->signingKey = NestedTokenFactory::signingKey();
        $this->clock = new MockClock('@'.self::NOW);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keyWrappingAlgorithms(): iterable
    {
        yield '128-bit key wrapping' => ['ECDH-ES+A128KW'];
        yield '192-bit key wrapping' => ['ECDH-ES+A192KW'];
        yield '256-bit key wrapping' => ['ECDH-ES+A256KW'];
    }

    #[DataProvider('keyWrappingAlgorithms')]
    public function test_processes_each_exact_userinfo_algorithm_profile(string $algorithm): void
    {
        $decryptionKey = NestedTokenFactory::encryptionKey($algorithm);
        $claims = $this->validClaims();
        $token = NestedTokenFactory::userInfo(
            $claims,
            $decryptionKey,
            $this->signingKey,
            $algorithm,
        );

        $userInfo = $this->process($token, $decryptionKey);

        $this->assertInstanceOf(VerifiedUserInfo::class, $userInfo);
        $this->assertSame($claims, $userInfo->claims());
        $this->assertSame($claims['person_info'], $userInfo->personInfo());
        $this->assertSame(self::SUBJECT, $userInfo->subject());
        $this->assertStringContainsString('[redacted]', print_r($userInfo, true));
        $this->assertStringNotContainsString('TEST USER', print_r($userInfo, true));
    }

    public function test_rejects_the_id_token_content_encryption_profile(): void
    {
        $token = NestedTokenFactory::userInfo(
            $this->validClaims(),
            $this->decryptionKey,
            $this->signingKey,
            contentEncryptionAlgorithm: 'A256CBC-HS512',
        );

        $this->expectException(InvalidUserInfoException::class);

        $this->process($token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mandatoryClaims(): iterable
    {
        yield 'person information' => ['person_info'];
        yield 'issuer' => ['iss'];
        yield 'issued at' => ['iat'];
        yield 'subject' => ['sub'];
        yield 'audience' => ['aud'];
    }

    #[DataProvider('mandatoryClaims')]
    public function test_rejects_each_missing_mandatory_claim(string $missingClaim): void
    {
        $claims = $this->validClaims();
        unset($claims[$missingClaim]);

        $this->expectException(InvalidUserInfoException::class);

        $this->process($this->token($claims));
    }

    /**
     * @return iterable<string, array{string, array|int|string}>
     */
    public static function invalidClaims(): iterable
    {
        yield 'person information scalar' => ['person_info', 'not-an-object'];
        yield 'empty person information list' => ['person_info', []];
        yield 'person information list' => ['person_info', [['value' => 'unexpected']]];
        yield 'issuer type' => ['iss', 123];
        yield 'wrong issuer' => ['iss', 'https://attacker.example/fapi'];
        yield 'issued-at type' => ['iat', (string) self::NOW];
        yield 'future issued-at' => ['iat', self::NOW + 3];
        yield 'subject type' => ['sub', 123];
        yield 'empty subject' => ['sub', ' '];
        yield 'mismatched subject' => ['sub', 'different-subject'];
        yield 'audience type' => ['aud', 123];
        yield 'wrong audience' => ['aud', 'other-client'];
        yield 'empty audience list' => ['aud', []];
        yield 'associative audience' => ['aud', ['client' => self::CLIENT_ID]];
        yield 'heterogeneous audience list' => ['aud', [self::CLIENT_ID, 123]];
    }

    #[DataProvider('invalidClaims')]
    public function test_rejects_invalid_claim_types_and_bindings(
        string $claim,
        array|int|string $value,
    ): void
    {
        $claims = $this->validClaims();
        $claims[$claim] = $value;

        $this->expectException(InvalidUserInfoException::class);

        $this->process($this->token($claims));
    }

    public function test_accepts_oidc_audience_list_shape(): void
    {
        $claims = $this->validClaims();
        $claims['aud'] = ['another-audience', self::CLIENT_ID];

        $userInfo = $this->process($this->token($claims));

        $this->assertSame($claims['aud'], $userInfo->claims()['aud']);
    }

    public function test_accepts_an_empty_person_information_json_object(): void
    {
        $claims = $this->validClaims();
        $claims['person_info'] = (object) [];

        $userInfo = $this->process($this->token($claims));

        $this->assertSame([], $userInfo->personInfo());
    }

    public function test_does_not_require_a_nonce_or_expiry_claim(): void
    {
        $claims = $this->validClaims();
        $this->assertArrayNotHasKey('nonce', $claims);
        $this->assertArrayNotHasKey('exp', $claims);

        $userInfo = $this->process($this->token($claims));

        $this->assertSame($claims, $userInfo->claims());
    }

    public function test_validates_optional_expiry_when_present(): void
    {
        $validClaims = $this->validClaims();
        $validClaims['exp'] = self::NOW + 300;

        $this->assertSame(
            self::NOW + 300,
            $this->process($this->token($validClaims))->claims()['exp'],
        );

        foreach ([self::NOW - 3, (string) (self::NOW + 300)] as $invalidExpiry) {
            $invalidClaims = $this->validClaims();
            $invalidClaims['exp'] = $invalidExpiry;

            try {
                $this->process($this->token($invalidClaims));
                $this->fail('Expected invalid optional expiry to fail.');
            } catch (InvalidUserInfoException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_low_level_processor_validates_subject_shape_without_claiming_subject_binding(): void
    {
        $claims = $this->validClaims();
        $claims['sub'] = 'unbound-but-valid-subject';
        $token = $this->token($claims);

        $userInfo = (new UserInfoProcessor(new NestedJwtDecoder, $this->clock))->processUnbound(
            $token,
            new JWKSet([$this->decryptionKey]),
            new JWKSet([$this->signingKey->toPublic()]),
            self::ISSUER,
            self::CLIENT_ID,
        );

        $this->assertSame('unbound-but-valid-subject', $userInfo->subject());
    }

    public function test_errors_do_not_disclose_token_or_private_key_material(): void
    {
        $token = 'raw.userinfo.token';

        try {
            $this->process($token);
            $this->fail('Expected malformed UserInfo to fail.');
        } catch (InvalidUserInfoException $exception) {
            $this->assertSame('The UserInfo response is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString($token, $exception->getMessage());
            $this->assertStringNotContainsString(
                (string) $this->decryptionKey->get('d'),
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param array<string, array|bool|float|int|object|string|null> $claims
     */
    private function token(array $claims): string
    {
        return NestedTokenFactory::userInfo(
            $claims,
            $this->decryptionKey,
            $this->signingKey,
        );
    }

    private function process(string $token, ?JWK $decryptionKey = null): VerifiedUserInfo
    {
        return (new UserInfoProcessor(new NestedJwtDecoder, $this->clock))->process(
            $token,
            new JWKSet([$decryptionKey ?? $this->decryptionKey]),
            new JWKSet([$this->signingKey->toPublic()]),
            self::ISSUER,
            self::CLIENT_ID,
            self::SUBJECT,
        );
    }

    /**
     * @return array{
     *     person_info: array{name: array{value: string}},
     *     iss: string,
     *     iat: int,
     *     sub: string,
     *     aud: string
     * }
     */
    private function validClaims(): array
    {
        return [
            'person_info' => [
                'name' => ['value' => 'TEST USER'],
            ],
            'iss' => self::ISSUER,
            'iat' => self::NOW,
            'sub' => self::SUBJECT,
            'aud' => self::CLIENT_ID,
        ];
    }
}
