<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests;

use Carbon\CarbonImmutable;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\SoloRequest;
use Saloon\Traits\Body\HasFormBody;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\ClientAssertionSigningKeyResolver;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\DPoPProofGenerator;
use Illuminate\Support\Str;

class GetAccessTokenRequest extends SoloRequest implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function __construct(
        private string $tokenEndpoint,
        private string $code,
        private string $issuer,
        private string $redirectUri,
        private JWK $dpopPrivateSigningJwk,
        private JWK $dpopPublicSigningJwk
    )
    {
    }

    public function resolveEndpoint(): string
    {
        return $this->tokenEndpoint;
    }

    /**
     * @throws \JsonException
     */
    public function defaultHeaders(): array
    {
        $dpopProof = DPoPProofGenerator::make(
            'POST',
            $this->tokenEndpoint,
            $this->dpopPrivateSigningJwk,
            $this->dpopPublicSigningJwk
        );

        return [
            'DPoP' => $dpopProof,
        ];
    }

    /**
     * @throws \JsonException
     */
    public function defaultBody(): array
    {
        $signingConfiguration = ClientAssertionSigningKeyResolver::resolve();
        $jwsBuilder = new JWSBuilder($signingConfiguration['algorithm_manager']);
        $now = CarbonImmutable::now();
        $clientId = config('laravel-myinfo-sg-v6.client_id');

        $payload = json_encode([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $this->issuer,
            'iat' => $now->timestamp,
            'exp' => $now->addMinutes(2)->timestamp,
            'jti' => (string) Str::uuid(),
            'code' => $this->code,
        ], JSON_THROW_ON_ERROR);

        $jws = $jwsBuilder->create()
            ->withPayload($payload)
            ->addSignature($signingConfiguration['jwk'], [
                'typ' => 'JWT',
                'alg' => $signingConfiguration['alg'],
                'kid' => $signingConfiguration['jwk']->get('kid'),
            ])
            ->build();

        $compactSerializer = new CompactSerializer;
        $clientAssertion = $compactSerializer->serialize($jws);

        return [
            'grant_type' => 'authorization_code',
            'code' => $this->code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $clientId,
            'code_verifier' => session(config('laravel-myinfo-sg-v6.code_verifier_session_key')),
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $clientAssertion,
        ];
    }
}
