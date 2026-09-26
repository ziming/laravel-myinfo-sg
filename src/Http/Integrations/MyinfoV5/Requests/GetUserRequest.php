<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests;

use InvalidArgumentException;
use Jose\Component\Core\JWK;
use Saloon\Enums\Method;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\MyinfoV5Request;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Responses\GetUserResponse;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\DPoPProofGenerator;

class GetUserRequest extends MyinfoV5Request
{
    protected Method $method = Method::GET;

    protected ?string $response = GetUserResponse::class;

    private ?JWK $dpopPrivateSigningJwk;

    private ?string $dpopProof;

    /** @var (\Closure(): string)|null */
    private ?\Closure $dpopProofFactory = null;

    public function __construct(
        private string $userInfoEndpoint,
        private string $accessToken,
        JWK|string $dpopPrivateSigningJwk,
        ?JWK $deprecatedPublicSigningJwk = null,
    ) {
        parent::__construct();
        $this->enableSafeReadRetries();
        $this->tries = 1;

        if (is_string($dpopPrivateSigningJwk)) {
            if (trim($dpopPrivateSigningJwk) === '' || $deprecatedPublicSigningJwk !== null) {
                throw new InvalidArgumentException('The precomputed DPoP proof is invalid.');
            }

            $this->dpopPrivateSigningJwk = null;
            $this->dpopProof = $dpopPrivateSigningJwk;
            return;
        }

        if (
            $deprecatedPublicSigningJwk !== null
            && $deprecatedPublicSigningJwk->all() != $dpopPrivateSigningJwk->toPublic()->all()
        ) {
            throw new InvalidArgumentException('The DPoP public key does not match the private key.');
        }

        $this->dpopPrivateSigningJwk = $dpopPrivateSigningJwk;
        $this->dpopProof = null;
    }

    /**
     * Supply a fresh DPoP proof every time Saloon builds a pending request.
     *
     * @param \Closure(): string $dpopProofFactory
     * @internal
     */
    public static function withDpopProofFactory(
        string $userInfoEndpoint,
        #[\SensitiveParameter] string $accessToken,
        \Closure $dpopProofFactory,
    ): self {
        $request = new self($userInfoEndpoint, $accessToken, '[deferred]');
        $request->dpopProof = null;
        $request->dpopProofFactory = $dpopProofFactory;

        return $request;
    }

    public function resolveEndpoint(): string
    {
        return $this->userInfoEndpoint;
    }

    /**
     * @throws \JsonException
     */
    public function defaultHeaders(): array
    {
        if ($this->dpopProofFactory instanceof \Closure) {
            $dpopProof = ($this->dpopProofFactory)();
        } elseif ($this->dpopProof !== null) {
            $dpopProof = $this->dpopProof;
        } else {
            if (! $this->dpopPrivateSigningJwk instanceof JWK) {
                throw new InvalidArgumentException('The DPoP request context is invalid.');
            }

            $dpopProof = DPoPProofGenerator::make(
                'GET',
                $this->userInfoEndpoint,
                $this->dpopPrivateSigningJwk,
                accessToken: $this->accessToken,
            );
        }

        if (trim($dpopProof) === '') {
            throw new InvalidArgumentException('The DPoP proof factory returned an invalid proof.');
        }

        return [
            'Authorization' => 'DPoP '.$this->accessToken,
            'DPoP' => $dpopProof,
        ];
    }
}
