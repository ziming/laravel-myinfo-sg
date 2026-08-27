<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests;

use InvalidArgumentException;
use Jose\Component\Core\JWK;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\SoloRequest;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\DPoPProofGenerator;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses\GetUserResponse;

class GetUserRequest extends SoloRequest
{
    protected Method $method = Method::GET;

    protected ?string $response = GetUserResponse::class;

    private ?JWK $dpopPrivateSigningJwk;

    private ?JWK $dpopPublicSigningJwk;

    private ?string $dpopProof;

    public function __construct(
        private string $userInfoEndpoint,
        private string $accessToken,
        JWK|string $dpopPrivateSigningJwk,
        ?JWK $dpopPublicSigningJwk = null,
    ) {
        if (is_string($dpopPrivateSigningJwk)) {
            if (trim($dpopPrivateSigningJwk) === '' || $dpopPublicSigningJwk !== null) {
                throw new InvalidArgumentException('The precomputed DPoP proof is invalid.');
            }

            $this->dpopPrivateSigningJwk = null;
            $this->dpopPublicSigningJwk = null;
            $this->dpopProof = $dpopPrivateSigningJwk;

            return;
        }

        if (! $dpopPublicSigningJwk instanceof JWK) {
            throw new InvalidArgumentException('The DPoP key pair is incomplete.');
        }

        $this->dpopPrivateSigningJwk = $dpopPrivateSigningJwk;
        $this->dpopPublicSigningJwk = $dpopPublicSigningJwk;
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
            if (
                ! $this->dpopPrivateSigningJwk instanceof JWK
                || ! $this->dpopPublicSigningJwk instanceof JWK
            ) {
                throw new InvalidArgumentException('The DPoP request context is invalid.');
            }

            $dpopProof = DPoPProofGenerator::make(
                'GET',
                $this->userInfoEndpoint,
                $this->dpopPrivateSigningJwk,
                $this->dpopPublicSigningJwk,
                $this->accessToken,
            );
        }

        return [
            'Authorization' => 'DPoP '.$this->accessToken,
            'DPoP' => $dpopProof,
        ];
    }
}
