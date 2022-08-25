<?php

namespace Ziming\LaravelMyinfoSg;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Ziming\LaravelMyinfoSg\Exceptions\AccessTokenNotFoundException;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidAccessTokenException;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidDataOrSignatureForPersonDataException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoPersonDataNotFoundException;
use Ziming\LaravelMyinfoSg\Exceptions\SubNotFoundException;
use Ziming\LaravelMyinfoSg\Services\MyinfoSecurityService;

class LaravelMyinfoSg
{
    public function __construct(
        private ?string $clientId = null,
        private ?string $clientSecret = null,
        private ?string $attributes = null,
        private ?string $purpose = null,
        private ?string $redirectUri = null,
    )
    {
        $this->clientId = $clientId ?? config('laravel-myinfo-sg.client_id');
        $this->clientSecret = $clientSecret ?? config('laravel-myinfo-sg.client_secret');
        $this->attributes = $attributes ?? config('laravel-myinfo-sg.attributes');
        $this->purpose = $purpose ?? config('laravel-myinfo-sg.purpose');
        $this->redirectUri = $redirectUri ?? config('laravel-myinfo-sg.redirect_url');
    }

    /**
     * Generate MyInfo Authorise API URI to redirect to.
     */
    public function generateAuthoriseApiUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId,
            'attributes' => $this->attributes,
            'purpose' => $this->purpose,
            'state' => $state,
            'redirect_uri' => $this->redirectUri,
        ]);

        $query = urldecode($query);

        $redirectUri = config('laravel-myinfo-sg.api_authorise_url').'?'.$query;

        return $redirectUri;
    }

    /**
     * Everything below will be related to Getting MyInfo Person Data.
     */
    /**
     * Get MyInfo Person Data in an array with a 'data' key.
     *
     * @throws Exception
     * @return array<string, mixed>|array<string, mixed[]>
     */
    public function getMyinfoPersonData(string $code): array
    {
        $tokenRequestResponse = $this->createTokenRequest($code);

        $tokenRequestResponseBody = $tokenRequestResponse->getBody();

        $decoded = json_decode($tokenRequestResponseBody, true);

        if ($decoded) {
            return $this->callPersonAPI($decoded['access_token']);
        }

        throw new AccessTokenNotFoundException;
    }

    /**
     * Create Token Request.
     *
     * @throws Exception
     */
    private function createTokenRequest(string $code): ResponseInterface
    {
        $guzzleClient = new Client;

        $contentType = 'application/x-www-form-urlencoded';
        $method = 'POST';

        $params = [
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
        ];

        $headers = [
            'Cache-Control' => 'no-cache',
            'Content-Type' => $contentType,
            'Accept-Encoding' => 'gzip',
        ];

        if (config('laravel-myinfo-sg.debug_mode')) {
            Log::debug('-- Token Call --');
            Log::debug('Server Call Time: '.Carbon::now()->toDayDateTimeString());
            Log::debug('Authorisation Code: '.$code);
            Log::debug('Web Request URL: '.config('laravel-myinfo-sg.api_token_url'));
        }

        if (config('laravel-myinfo-sg.auth_level') === 'L2') {
            $authHeaders = MyinfoSecurityService::generateAuthorizationHeader(
                config('laravel-myinfo-sg.api_token_url'),
                $params,
                $method,
                $contentType,
                config('laravel-myinfo-sg.auth_level'),
                $this->clientId,
                $this->clientSecret,
            );

            $headers['Authorization'] = $authHeaders;

            if (config('laravel-myinfo-sg.debug_mode')) {
                Log::debug('Authorization Header: '.$authHeaders);
            }
        }

        $response = $guzzleClient->post(config('laravel-myinfo-sg.api_token_url'), [
            'form_params' => $params,
            'headers' => $headers,
        ]);

        return $response;
    }

    /**
     * Call Person API.
     *
     * @throws Exception
     * @return array<string, mixed>|array<string, mixed[]>
     */
    private function callPersonAPI(string $accessToken): array
    {
        $decoded = MyinfoSecurityService::verifyJWS($accessToken);

        if ($decoded === null) {
            throw new InvalidAccessTokenException;
        }

        $sub = $decoded['sub'];

        if ($sub === null) {
            throw new SubNotFoundException;
        }

        $personRequestResponse = $this->createPersonRequest($sub, $accessToken);
        $personRequestResponseBody = $personRequestResponse->getBody();
        $personRequestResponseContent = $personRequestResponseBody->getContents();

        if ($personRequestResponseContent) {
            $personData = json_decode($personRequestResponseContent, true);

            $authLevel = config('laravel-myinfo-sg.auth_level');

            if ($authLevel === 'L0') {
                return [
                    'data' => $personData,
                ];
            } elseif ($authLevel === 'L2') {
                $personData = $personRequestResponseContent;

                $personDataJWS = MyInfoSecurityService::decryptJWE(
                    $personData,
                    $this->clientSecret
                );

                if ($personDataJWS === null) {
                    throw new InvalidDataOrSignatureForPersonDataException;
                }

                $decodedPersonData = MyInfoSecurityService::verifyJWS($personDataJWS);

                if ($decodedPersonData === null) {
                    throw new InvalidDataOrSignatureForPersonDataException;
                }

                return [
                    'data' => $decodedPersonData,
                ];
            }
        }

        throw new MyinfoPersonDataNotFoundException;
    }

    /**
     * Create Person Request.
     *
     * @throws Exception
     */
    private function createPersonRequest(string $sub, string $validAccessToken): ResponseInterface
    {
        $guzzleClient = new Client;

        $url = config('laravel-myinfo-sg.api_person_url')."/{$sub}/";

        $params = [
            'client_id' => $this->clientId,
            'attributes' => $this->attributes,
        ];

        $headers = [
            'Cache-Control' => 'no-cache',
            'Accept-Encoding' => 'gzip',
        ];

        if (config('laravel-myinfo-sg.debug_mode')) {
            Log::debug('-- Person Call --');
            Log::debug('Server Call Time: '.Carbon::now()->toDayDateTimeString());
            Log::debug('Bearer Token: '.$validAccessToken);
            Log::debug('Web Request URL: '.$url);
        }

        $authHeaders = MyInfoSecurityService::generateAuthorizationHeader(
            $url,
            $params,
            'GET',
            '',
            config('laravel-myinfo-sg.auth_level'),
            $this->clientId,
            $this->clientSecret
        );

        if ($authHeaders) {
            $headers['Authorization'] = $authHeaders.',Bearer '.$validAccessToken;
        } else {
            $headers['Authorization'] = 'Bearer '.$validAccessToken;
        }

        if (config('laravel-myinfo-sg.debug_mode')) {
            Log::debug('-- Person Call --');
            Log::debug('Server Call Time: '.Carbon::now()->toDayDateTimeString());
            Log::debug('Bearer Token: '.$validAccessToken);
            Log::debug('Authorization Header: '.$headers['Authorization']);
        }

        $response = $guzzleClient->get($url, [
            'query' => $params,
            'headers' => $headers,
        ]);

        return $response;
    }

    public function setAttributes(array|string $attributes): void
    {
        if (is_string($attributes)) {
            $this->attributes = $attributes;
        } elseif (is_array($attributes)) {
            $this->attributes = join(',', $attributes);
        }
    }
}
