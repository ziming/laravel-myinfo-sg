<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests;

use Carbon\CarbonImmutable;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

class GetAccessTokenRequest extends \Saloon\Http\OAuth2\GetAccessTokenRequest
{
    /**
     * @throws \JsonException
     */
    public function defaultBody(): array
    {
        $jwkSet = JWKSet::createFromJson(
            config('laravel-myinfo-sg-v5.private_jwks')
        );

        $chosenJwk = $jwkSet->get(
            config('laravel-myinfo-sg-v5.chosen_jwks_sig_kid')
        );

        $algorithmManager = new AlgorithmManager([new ES256]);

        $jwsBuilder = new JWSBuilder($algorithmManager);

        $now = CarbonImmutable::now();

        $clientId = $this->oauthConfig->getClientId();

        $payload = json_encode([
            'iss' => $clientId,
            'sub' => $clientId,

            'aud' => $this->oauthConfig->getTokenEndpoint(),

            'iat' => $now->timestamp,
            'exp' => $now->addMinutes(2)->timestamp,
            'code' => $this->code,
        ], JSON_THROW_ON_ERROR);

        $jws = $jwsBuilder->create()
            ->withPayload($payload)
            ->addSignature($chosenJwk, [
                'typ' => 'JWT',
                'alg' => 'ES256',
                'kid' => config('laravel-myinfo-sg-v5.chosen_jwks_sig_kid'),
            ])
            ->build();

        $compactSerializer = new CompactSerializer;

        $clientAssertion = $compactSerializer->serialize($jws);

        return [
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'grant_type' => 'authorization_code',
            'code' => $this->code,
            'client_id' => $clientId,
            'redirect_uri' => $this->oauthConfig->getRedirectUri(),
            'client_assertion' => $clientAssertion,
            'code_verifier' => session(
                config('laravel-myinfo-sg-v5.code_verifier.session_key')
            ),
        ];
    }
}
