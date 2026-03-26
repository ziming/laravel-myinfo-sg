<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetUserRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\PushedAuthorizationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses\GetUserResponse;
use Illuminate\Support\Str;
use Saloon\Http\Connector;

class MyinfoConnector extends Connector
{
    /**
     * @throws \JsonException
     */
    public function generateAuthorizationUrl(?string $redirectUri = null): string
    {
        $effectiveRedirectUri = $redirectUri ?? config('laravel-myinfo-sg-v6.redirect_uri');
        $codeVerifier = Str::random(128);
        $encoded = base64_encode(hash('sha256', $codeVerifier, true));
        $codeChallenge = strtr(rtrim($encoded, '='), '+/', '-_');

        $state = Str::random(40);
        $nonce = (string) Str::uuid();
        [$dpopPrivateJwk, $dpopPublicJwk] = $this->createAndStoreDpopKeyPair();

        session()->put([
            config('laravel-myinfo-sg-v6.state_session_key') => $state,
            config('laravel-myinfo-sg-v6.nonce_session_key') => $nonce,
            config('laravel-myinfo-sg-v6.code_verifier_session_key') => $codeVerifier,
            config('laravel-myinfo-sg-v6.redirect_uri_session_key') => $effectiveRedirectUri,
        ]);

        // Fetch OIDC configuration
        $getSingpassOpenIdConfigurationRequest = new GetSingpassOpenIdConfigurationRequest;
        $configResponse = $getSingpassOpenIdConfigurationRequest->send();
        $configData = $configResponse->json();

        $parEndpoint = $configData['pushed_authorization_request_endpoint'];
        $authorizationEndpoint = $configData['authorization_endpoint'];
        $issuer = $configData['issuer'];

        // Call PAR endpoint
        $parRequest = new PushedAuthorizationRequest(
            $parEndpoint,
            $issuer,
            $dpopPrivateJwk,
            $dpopPublicJwk,
            $state,
            $nonce,
            $codeChallenge,
            $effectiveRedirectUri
        );
        $parResponse = $parRequest->send();
        $parData = $parResponse->json();

        $requestUri = $parData['request_uri'];

        // Build the authorization URL with only client_id and request_uri
        $authorizationUrl = $authorizationEndpoint . '?' . http_build_query([
            'client_id' => config('laravel-myinfo-sg-v6.client_id'),
            'request_uri' => $requestUri,
        ]);

        if (config('laravel-myinfo-sg-v6.debug_mode')) {
            Log::debug('-- MyInfo V6 Authorise Call --');
            Log::debug('Server Call Time: ' . Carbon::now()->toDayDateTimeString());
            Log::debug('Web Request URL: ' . $authorizationUrl);
            Log::debug('PAR Request URI: ' . $requestUri);
        }

        return $authorizationUrl;
    }

    /**
     * @throws \JsonException
     */
    public function getAccessToken(string $code): array
    {
        $getSingpassOpenIdConfigurationRequest = new GetSingpassOpenIdConfigurationRequest;
        $configResponse = $getSingpassOpenIdConfigurationRequest->send();
        $configData = $configResponse->json();

        $tokenEndpoint = $configData['token_endpoint'];
        $issuer = $configData['issuer'];
        [$dpopPrivateJwk, $dpopPublicJwk] = $this->getStoredDpopKeyPair();
        $redirectUri = session(
            config('laravel-myinfo-sg-v6.redirect_uri_session_key'),
            config('laravel-myinfo-sg-v6.redirect_uri')
        );

        $getAccessTokenRequest = new GetAccessTokenRequest(
            $tokenEndpoint,
            $code,
            $issuer,
            $redirectUri,
            $dpopPrivateJwk,
            $dpopPublicJwk
        );
        $response = $this->send($getAccessTokenRequest);

        return $response->json();
    }

    /**
     * @throws \JsonException
     */
    public function getUser(string $accessToken): GetUserResponse
    {
        $getSingpassOpenIdConfigurationRequest = new GetSingpassOpenIdConfigurationRequest;
        $configResponse = $getSingpassOpenIdConfigurationRequest->send();
        $configData = $configResponse->json();

        $userInfoEndpoint = $configData['userinfo_endpoint'];
        [$dpopPrivateJwk, $dpopPublicJwk] = $this->getStoredDpopKeyPair();

        $getUserRequest = new GetUserRequest(
            $userInfoEndpoint,
            $accessToken,
            $dpopPrivateJwk,
            $dpopPublicJwk
        );

        /** @var GetUserResponse $response */
        $response = $this->send($getUserRequest);

        return $response;
    }

    public function resolveBaseUrl(): string
    {
        return config('laravel-myinfo-sg-v6.issuer_uri');
    }

    /**
     * @return array{JWK, JWK}
     * @throws \JsonException
     */
    private function createAndStoreDpopKeyPair(): array
    {
        $privateJwk = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
        ]);

        session()->put(
            config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key'),
            json_encode($privateJwk, JSON_THROW_ON_ERROR)
        );

        return [$privateJwk, $privateJwk->toPublic()];
    }

    /**
     * @return array{JWK, JWK}
     */
    private function getStoredDpopKeyPair(): array
    {
        $privateJwkJson = session(
            config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key')
        );

        if (! is_string($privateJwkJson) || $privateJwkJson === '') {
            throw new \RuntimeException('No DPoP private key found in session');
        }

        $privateJwk = JWKFactory::createFromJsonObject($privateJwkJson);

        if (! $privateJwk instanceof JWK) {
            throw new \RuntimeException('Expected a single DPoP JWK in session');
        }

        return [$privateJwk, $privateJwk->toPublic()];
    }
}
