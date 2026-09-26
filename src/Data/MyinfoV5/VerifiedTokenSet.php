<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Data\MyinfoV5;

use InvalidArgumentException;
use Jose\Component\Core\JWK;
use LogicException;
use SensitiveParameter;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\DPoPProofGenerator;

final readonly class VerifiedTokenSet
{
    /** @var \Closure(): JWK */
    private \Closure $dpopContext;

    /**
     * ID-token claims are an extensible JSON object, so their values are
     * necessarily heterogeneous even though stable claims have typed accessors.
     *
     * @param array<string, mixed> $claims
     */
    public function __construct(
        #[SensitiveParameter] private string $accessToken,
        private array $claims,
        private string $tokenType,
        #[SensitiveParameter] JWK $dpopPrivateJwk,
    ) {
        $subject = $claims['sub'] ?? null;

        if (
            trim($accessToken) === ''
            || $tokenType !== 'DPoP'
            || ! is_string($subject)
            || trim($subject) === ''
            || ! $dpopPrivateJwk->has('d')
        ) {
            throw new InvalidArgumentException('Verified token set values are invalid.');
        }

        $this->dpopContext = static fn (): JWK => $dpopPrivateJwk;
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Provider-defined ID-token claim values remain heterogeneous by design.
     *
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    public function subject(): string
    {
        $subject = $this->claims['sub'];

        return is_string($subject) ? $subject : throw new LogicException('Verified subject is unavailable.');
    }

    public function tokenType(): string
    {
        return $this->tokenType;
    }

    /** @internal */
    public function dpopSigningAlgorithm(): string
    {
        return DPoPProofGenerator::signingAlgorithm(($this->dpopContext)());
    }

    /**
     * Create the access-token-bound proof required for the UserInfo request while
     * keeping the transaction's private DPoP key internal.
     *
     * @internal
     * @throws \JsonException
     */
    public function createUserInfoDpopProof(string $userInfoEndpoint): string
    {
        $dpopPrivateJwk = ($this->dpopContext)();

        return DPoPProofGenerator::make(
            'GET',
            $userInfoEndpoint,
            $dpopPrivateJwk,
            accessToken: $this->accessToken,
        );
    }

    /**
     * Keep access-token and private-key material out of debug output.
     *
     * @return array{token_type: string, access_token: string, claims: string, dpop_context: string}
     */
    public function __debugInfo(): array
    {
        $dpopPrivateJwk = ($this->dpopContext)();

        return [
            'token_type' => $this->tokenType,
            'access_token' => '[redacted]',
            'claims' => '[verified]',
            'dpop_context' => $dpopPrivateJwk->has('d') ? '[bound]' : '[invalid]',
        ];
    }

    /**
     * The transaction-bound DPoP key must never be serialized.
     *
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Verified token sets cannot be serialized.');
    }
}
