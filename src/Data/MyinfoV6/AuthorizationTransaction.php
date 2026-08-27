<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Data\MyinfoV6;

use InvalidArgumentException;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\SingpassAlgorithmProfile;

final readonly class AuthorizationTransaction
{
    private string $dpopPrivateJwkJson;

    public function __construct(
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $redirectUri,
        public string $issuer,
        #[SensitiveParameter] string $dpopPrivateJwkJson,
        public int $createdAt,
        public string $dpopSigningAlg = 'ES256',
    ) {
        if ($state === '' || $nonce === '' || $codeVerifier === '' || $redirectUri === '' || $issuer === '') {
            throw new InvalidArgumentException('Authorization transaction fields must not be empty.');
        }

        if ($createdAt < 0) {
            throw new InvalidArgumentException('Authorization transaction creation time is invalid.');
        }

        $this->dpopPrivateJwkJson = self::normalizePrivateJwk($dpopPrivateJwkJson, $dpopSigningAlg);
    }

    public function dpopPrivateJwk(): JWK
    {
        $jwk = JWKFactory::createFromJsonObject($this->dpopPrivateJwkJson);

        if (! $jwk instanceof JWK) {
            throw new RuntimeException('Authorization transaction DPoP key is invalid.');
        }

        return $jwk;
    }

    /**
     * @return array{
     *     state: string,
     *     nonce: string,
     *     code_verifier: string,
     *     redirect_uri: string,
     *     issuer: string,
     *     dpop_private_jwk: string,
     *     created_at: int,
     *     dpop_signing_alg: string
     * }
     */
    public function toSessionRecord(): array
    {
        return [
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'redirect_uri' => $this->redirectUri,
            'issuer' => $this->issuer,
            'dpop_private_jwk' => $this->dpopPrivateJwkJson,
            'created_at' => $this->createdAt,
            'dpop_signing_alg' => $this->dpopSigningAlg,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function fromSessionRecord(array $record): self
    {
        $state = $record['state'] ?? null;
        $nonce = $record['nonce'] ?? null;
        $codeVerifier = $record['code_verifier'] ?? null;
        $redirectUri = $record['redirect_uri'] ?? null;
        $issuer = $record['issuer'] ?? null;
        $dpopPrivateJwk = $record['dpop_private_jwk'] ?? null;
        $createdAt = $record['created_at'] ?? null;
        $dpopSigningAlg = $record['dpop_signing_alg'] ?? 'ES256';

        if (
            ! is_string($state)
            || ! is_string($nonce)
            || ! is_string($codeVerifier)
            || ! is_string($redirectUri)
            || ! is_string($issuer)
            || ! is_string($dpopPrivateJwk)
            || ! is_int($createdAt)
            || ! is_string($dpopSigningAlg)
        ) {
            throw new InvalidArgumentException('Authorization transaction record is invalid.');
        }

        return new self(
            $state,
            $nonce,
            $codeVerifier,
            $redirectUri,
            $issuer,
            $dpopPrivateJwk,
            $createdAt,
            $dpopSigningAlg,
        );
    }

    private static function normalizePrivateJwk(
        #[SensitiveParameter] string $jwkJson,
        string $dpopSigningAlg,
    ): string
    {
        try {
            $jwk = JWKFactory::createFromJsonObject($jwkJson);
            $expectedCurve = SingpassAlgorithmProfile::dpopCurve($dpopSigningAlg);

            if (
                ! $jwk instanceof JWK
                || $jwk->get('kty') !== 'EC'
                || $jwk->get('use') !== 'sig'
                || $jwk->get('alg') !== $dpopSigningAlg
                || $jwk->get('crv') !== $expectedCurve
                || ! self::hasNonEmptyString($jwk, 'x')
                || ! self::hasNonEmptyString($jwk, 'y')
                || ! self::hasNonEmptyString($jwk, 'd')
            ) {
                throw new InvalidArgumentException;
            }

            return json_encode($jwk, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Authorization transaction contains an invalid DPoP private key.');
        }
    }

    private static function hasNonEmptyString(JWK $jwk, string $parameter): bool
    {
        return $jwk->has($parameter)
            && is_string($jwk->get($parameter))
            && $jwk->get($parameter) !== '';
    }
}
