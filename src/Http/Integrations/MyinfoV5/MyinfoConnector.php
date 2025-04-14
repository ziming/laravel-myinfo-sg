<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5;

use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetUserRequest;
use Illuminate\Support\Str;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Traits\OAuth2\AuthorizationCodeGrant;

class MyinfoConnector extends Connector
{
    use AuthorizationCodeGrant;

    /**
     * @throws \JsonException
     */
    protected function defaultOauthConfig(): OAuthConfig
    {

        $getSingpassOpenIdConfigurationRequest = new GetSingpassOpenIdConfigurationRequest;

        $response = $getSingpassOpenIdConfigurationRequest->send();

        return OAuthConfig::make()
            ->setClientId(config('laravel-myinfo-sg-v5.client_id'))
            ->setClientSecret(Str::random()) // doesn't exist in myinfo v5. But need to set for Saloon to not throw error
            ->setDefaultScopes(config('laravel-myinfo-sg-v5.scope_array'))
            ->setRedirectUri(config('laravel-myinfo-sg-v5.redirect_uri'))
            ->setAuthorizeEndpoint(
                $response->json('authorization_endpoint'),
            )
            ->setTokenEndpoint(
                $response->json('token_endpoint'),
            )
            ->setUserEndpoint(
                $response->json('userinfo_endpoint'),
            );
    }

    protected function resolveAccessTokenRequest(string $code, OAuthConfig $oauthConfig): Request
    {
        return new GetAccessTokenRequest($code, $oauthConfig);
    }

    protected function resolveUserRequest(OAuthConfig $oauthConfig): Request
    {
        return new GetUserRequest($oauthConfig);
    }

    public function resolveBaseUrl(): string
    {
        return config('laravel-myinfo-sg-v5.issuer_uri');
    }
}
