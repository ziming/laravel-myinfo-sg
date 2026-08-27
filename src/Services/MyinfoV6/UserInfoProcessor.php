<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use Jose\Component\Checker\CallableChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Core\JWKSet;
use Psr\Clock\ClockInterface;
use stdClass;
use Symfony\Component\Clock\Clock;
use Throwable;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\VerifiedUserInfo;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidUserInfoException;

final readonly class UserInfoProcessor
{
    /** Local fail-closed policy: allow at most two seconds of clock skew. */
    private const int CLOCK_SKEW_SECONDS = 2;

    public function __construct(
        private NestedJwtDecoder $decoder = new NestedJwtDecoder,
        private ClockInterface $clock = new Clock,
    ) {
    }

    public function process(
        #[\SensitiveParameter] string $compactUserInfo,
        JWKSet $privateDecryptionJwks,
        JWKSet $singpassPublicJwks,
        string $expectedIssuer,
        string $clientId,
        string $expectedSubject,
    ): VerifiedUserInfo {
        if (trim($expectedSubject) === '') {
            throw new InvalidUserInfoException('The UserInfo response is invalid.');
        }

        return $this->processClaims(
            $compactUserInfo,
            $privateDecryptionJwks,
            $singpassPublicJwks,
            $expectedIssuer,
            $clientId,
            $expectedSubject,
        );
    }

    /**
     * Low-level compatibility processing without ID-token-to-UserInfo subject binding.
     *
     * @internal
     */
    public function processUnbound(
        #[\SensitiveParameter] string $compactUserInfo,
        JWKSet $privateDecryptionJwks,
        JWKSet $singpassPublicJwks,
        string $expectedIssuer,
        string $clientId,
    ): VerifiedUserInfo {
        return $this->processClaims(
            $compactUserInfo,
            $privateDecryptionJwks,
            $singpassPublicJwks,
            $expectedIssuer,
            $clientId,
            null,
        );
    }

    private function processClaims(
        #[\SensitiveParameter] string $compactUserInfo,
        JWKSet $privateDecryptionJwks,
        JWKSet $singpassPublicJwks,
        string $expectedIssuer,
        string $clientId,
        ?string $expectedSubject,
    ): VerifiedUserInfo {
        try {
            $decodedClaims = $this->decoder->decode(
                NestedJwtDecoder::USERINFO,
                $compactUserInfo,
                $privateDecryptionJwks,
                $singpassPublicJwks,
                true,
            );
            $personInfo = $decodedClaims['person_info'] ?? null;

            if (! $personInfo instanceof stdClass) {
                throw new InvalidUserInfoException('The UserInfo person information is invalid.');
            }

            $normalizedClaims = $this->normalizeJsonValue($decodedClaims);

            if (! is_array($normalizedClaims)) {
                throw new InvalidUserInfoException('The UserInfo claim set is invalid.');
            }

            $claims = $this->claimSet($normalizedClaims);
            $now = $this->clock->now()->getTimestamp();
            $checker = new ClaimCheckerManager([
                new CallableChecker(
                    'person_info',
                    static fn (array|bool|float|int|string|null $personInfo): bool => is_array($personInfo),
                ),
                new IssuerChecker([$expectedIssuer]),
                new CallableChecker(
                    'aud',
                    fn (array|bool|float|int|string|null $audience): bool => $this->validAudience(
                        $audience,
                        $clientId,
                    ),
                ),
                new CallableChecker(
                    'iat',
                    static fn (array|bool|float|int|string|null $issuedAt): bool => self::validIssuedAt(
                        $issuedAt,
                        $now,
                    ),
                ),
                new CallableChecker(
                    'sub',
                    static fn (array|bool|float|int|string|null $subject): bool => is_string($subject)
                        && trim($subject) !== ''
                        && ($expectedSubject === null || hash_equals($expectedSubject, $subject)),
                ),
                new CallableChecker(
                    'exp',
                    static fn (array|bool|float|int|string|null $expiresAt): bool => self::validExpiresAt(
                        $expiresAt,
                        $now,
                    ),
                ),
            ]);

            $checker->check($claims, ['person_info', 'iss', 'iat', 'sub', 'aud']);

            return new VerifiedUserInfo($claims);
        } catch (Throwable) {
            throw new InvalidUserInfoException('The UserInfo response is invalid.');
        }
    }

    private function validAudience(
        array|bool|float|int|string|null $audience,
        string $clientId,
    ): bool
    {
        if (is_string($audience)) {
            return hash_equals($clientId, $audience);
        }

        if (! is_array($audience) || ! array_is_list($audience) || $audience === []) {
            return false;
        }

        foreach ($audience as $value) {
            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return in_array($clientId, $audience, true);
    }

    /**
     * Claim values remain heterogeneous because JWT claim sets are extensible
     * JSON objects and may contain provider-defined nested structures.
     *
     * @param array<array-key, mixed> $claims
     * @return array<string, mixed>
     */
    private function claimSet(array $claims): array
    {
        $claimSet = [];

        foreach ($claims as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidUserInfoException('The UserInfo claim set is invalid.');
            }

            $claimSet[$name] = $value;
        }

        return $claimSet;
    }

    private static function validIssuedAt(
        array|bool|float|int|string|null $value,
        int $now,
    ): bool
    {
        if (! is_int($value) && (! is_float($value) || ! is_finite($value))) {
            return false;
        }

        return $value <= $now + self::CLOCK_SKEW_SECONDS;
    }

    private static function validExpiresAt(
        array|bool|float|int|string|null $value,
        int $now,
    ): bool
    {
        if (! is_int($value) && (! is_float($value) || ! is_finite($value))) {
            return false;
        }

        return $now < $value + self::CLOCK_SKEW_SECONDS;
    }

    private function normalizeJsonValue(
        stdClass|array|bool|float|int|string|null $value,
    ): array|bool|float|int|string|null {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            if (
                ! $nestedValue instanceof stdClass
                && ! is_array($nestedValue)
                && ! is_bool($nestedValue)
                && ! is_float($nestedValue)
                && ! is_int($nestedValue)
                && ! is_string($nestedValue)
                && $nestedValue !== null
            ) {
                throw new InvalidUserInfoException('The UserInfo JSON value is invalid.');
            }

            $value[$key] = $this->normalizeJsonValue($nestedValue);
        }

        return $value;
    }
}
