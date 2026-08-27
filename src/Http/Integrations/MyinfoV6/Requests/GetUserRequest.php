<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests;

use InvalidArgumentException;
use Jose\Component\Core\JWK;
use Saloon\Enums\Method;
use Saloon\Http\SoloRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses\GetUserResponse;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\DPoPProofGenerator;

class GetUserRequest extends SoloRequest
{
    protected Method $method = Method::GET;

    protected ?string $response = GetUserResponse::class;

    private ?JWK $dpopPrivateSigningJwk;

    private ?string $dpopProof;

    public function __construct(
        private string $userInfoEndpoint,
        private string $accessToken,
        JWK|string $dpopPrivateSigningJwk,
        ?JWK $deprecatedPublicSigningJwk = null,
    ) {
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

    public function resolveEndpoint(): string
    {
        return $this->userInfoEndpoint;
    }

    /**
     * @throws \JsonException
     */
    public function defaultHeaders(): array
    {
        $dpopProof = $this->dpopProof;

        if ($dpopProof === null) {
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

        return [
            'Authorization' => 'DPoP '.$this->accessToken,
            'DPoP' => $dpopProof,
        ];
    }
}
