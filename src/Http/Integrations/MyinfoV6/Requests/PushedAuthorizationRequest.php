<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests;

use Carbon\CarbonImmutable;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\SoloRequest;
use Saloon\Traits\Body\HasFormBody;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\ClientAssertionSigningKeyResolver;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\DPoPProofGenerator;
use Illuminate\Support\Str;

class PushedAuthorizationRequest extends SoloRequest implements HasBody
{
    protected Method $method = Method::POST;

    use HasFormBody;

    public function __construct(
        private string $parEndpoint,
        private string $issuer,
        private JWK $dpopPrivateSigningJwk,
        private JWK $dpopPublicSigningJwk,
        private string $state,
        private string $nonce,
        private string $codeChallenge,
        private ?string $redirectUri = null
    ) {
    }

    public function resolveEndpoint(): string
    {
        return $this->parEndpoint;
    }

    protected function resolveConnector(): Connector
    {
        return new MyinfoConnector;
    }

    /**
     * @throws \JsonException
     */
    public function defaultHeaders(): array
    {
        $dpopProof = DPoPProofGenerator::make(
            'POST',
            $this->parEndpoint,
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
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri ?? config('laravel-myinfo-sg-v6.redirect_uri'),
            'scope' => config('laravel-myinfo-sg-v6.scopes'),
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $clientAssertion,
        ];
    }
}
