<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use Jose\Component\Checker\CallableChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Core\JWKSet;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Throwable;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidIdTokenException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\SigningKeyRefreshRequiredException;

final readonly class IdTokenProcessor
{
    /** Local fail-closed policy: allow at most two seconds of clock skew. */
    private const int CLOCK_SKEW_SECONDS = 2;

    public function __construct(
        private NestedJwtDecoder $decoder = new NestedJwtDecoder,
        private ClockInterface $clock = new Clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function process(
        #[\SensitiveParameter] string $idToken,
        JWKSet $privateDecryptionJwks,
        JWKSet $singpassPublicJwks,
        string $expectedIssuer,
        string $clientId,
        string $expectedNonce,
    ): array {
        try {
            $decodedClaims = $this->decoder->decode(
                NestedJwtDecoder::ID_TOKEN,
                $idToken,
                $privateDecryptionJwks,
                $singpassPublicJwks,
            );
            $claims = $this->claimSet($decodedClaims);

            $now = $this->clock->now()->getTimestamp();
            $checker = new ClaimCheckerManager([
                new IssuerChecker([$expectedIssuer]),
                new CallableChecker('aud', fn (mixed $audience): bool => $this->validAudience($audience, $clientId)),
                new CallableChecker('exp', static fn (mixed $expiresAt): bool => self::numericDate($expiresAt)
                    && $now < $expiresAt + self::CLOCK_SKEW_SECONDS),
                new CallableChecker('iat', static fn (mixed $issuedAt): bool => self::numericDate($issuedAt)
                    && $issuedAt <= $now + self::CLOCK_SKEW_SECONDS),
                new CallableChecker('nonce', static fn (mixed $nonce): bool => is_string($nonce)
                    && hash_equals($expectedNonce, $nonce)),
                new CallableChecker('sub', static fn (mixed $subject): bool => is_string($subject)
                    && trim($subject) !== ''),
            ]);

            $checker->check($claims, ['iss', 'aud', 'exp', 'iat', 'nonce', 'sub']);

            return $claims;
        } catch (SigningKeyRefreshRequiredException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidIdTokenException('The ID token is invalid.');
        }
    }

    private function validAudience(mixed $audience, string $clientId): bool
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
     * @param array<array-key, mixed> $claims
     * @return array<string, mixed>
     */
    private function claimSet(array $claims): array
    {
        $claimSet = [];

        foreach ($claims as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidIdTokenException('The ID token claim set is invalid.');
            }

            $claimSet[$name] = $value;
        }

        return $claimSet;
    }

    private static function numericDate(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }
}
