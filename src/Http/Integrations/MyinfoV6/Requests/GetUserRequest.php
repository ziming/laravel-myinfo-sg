<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests;

use Jose\Component\Core\JWK;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\DPoPProofGenerator;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses\GetUserResponse;

class GetUserRequest extends Request
{
    protected Method $method = Method::GET;

    protected ?string $response = GetUserResponse::class;

    public function __construct(
        private string $userInfoEndpoint,
        private string $accessToken,
        private JWK $dpopPrivateSigningJwk,
        private JWK $dpopPublicSigningJwk
    )
    {
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
        $dpopProof = DPoPProofGenerator::make(
            'GET',
            $this->userInfoEndpoint,
            $this->dpopPrivateSigningJwk,
            $this->dpopPublicSigningJwk,
            $this->accessToken
        );

        return [
            'Authorization' => 'DPoP '.$this->accessToken,
            'DPoP' => $dpopProof,
        ];
    }
}
