<?php

namespace Ziming\LaravelMyinfoSg;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Jose\Component\Core\JWK;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Support\Carbon;
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
        private ?string $scope = null,
        private ?string $purposeId = null,
        private ?string $redirectUri = null,
    )
    {
        $this->clientId = $clientId ?? config('laravel-myinfo-sg.client_id');
        $this->clientSecret = $clientSecret ?? config('laravel-myinfo-sg.client_secret');
        $this->scope = $scope ?? config('laravel-myinfo-sg.scope');
        $this->purposeId = $purposeId ?? config('laravel-myinfo-sg.purpose_id');
        $this->redirectUri = $redirectUri ?? config('laravel-myinfo-sg.redirect_url');
    }

    /**
     * Generate MyInfo Authorise API URI to redirect to.
     */
    public function generateAuthoriseApiUrl(string $codeChallenge): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId,
            'scope' => $this->scope,
            'purpose_id' => $this->purposeId,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
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
     * @return array<string, mixed>|array<string, array>
     * @throws GuzzleException|Exception
     */
    public function getMyinfoPersonData(
        string $authCode,
        string $codeVerifier,
        string $privateSigningKey,
        array  $privateEncryptionKeys
    ): array
    {
        $sessionEphemeralKeyPair = MyinfoSecurityService::generateSessionKeyPair();

        $accessToken = $this->getAccessToken(
            $authCode,
            $codeVerifier,
            $sessionEphemeralKeyPair,
            $privateSigningKey,
        );

        return $this->getPersonData(
            $accessToken,
            $sessionEphemeralKeyPair,
            $privateEncryptionKeys,
        );
    }

    /**
     * D
     */
    private function getAccessToken(string $code, string $codeVerifier, JWK $sessionEphemeralKeyPair, string $privateSigningKey): string
    {
        if (config('laravel-myinfo-sg.debug_mode')) {
            Log::debug('-- Token Call --');
            Log::debug('Server Call Time: '.Carbon::now()->toDayDateTimeString());
            Log::debug('Authorisation Code: '.$code);
            Log::debug('Web Request URL: '.config('laravel-myinfo-sg.api_token_url'));
        }

        $response = $this->callTokenAPI($code, $privateSigningKey, $codeVerifier, $sessionEphemeralKeyPair);

        $responseBody = $response->getBody();

        $decoded = json_decode($responseBody, true);

        if ($decoded) {
            return $decoded['access_token'];
        }

        throw new AccessTokenNotFoundException;

    }

    public function callTokenAPI(string $authCode, string $privateSigningKey, string $codeVerifier, JWK $sessionEphemeralKeyPair): ResponseInterface
    {
        $guzzleClient = new Client;

        $contentType = 'application/x-www-form-urlencoded';
        $method = 'POST';

        $params = [
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_assertion' => 'ToDo',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'code' => $authCode,
            'code_verifier' => $codeVerifier,
        ];

        $headers = [
            'Cache-Control' => 'no-cache',
            'Content-Type' => $contentType,
            'Accept' => 'application/json',
            'Accept-Encoding' => 'gzip',
            'DPoP' => MyinfoSecurityService::generateDpop(
                config('laravel-myinfo-sg.api_token_url'),
                null,
                'POST',
                $sessionEphemeralKeyPair,
            ),
        ];

        $response = $guzzleClient->post(config('laravel-myinfo-sg.api_token_url'), [
            'form_params' => $params,
            'headers' => $headers,
        ]);

        return $response;
    }

    /**
     * Call Person API.
     *
     * @return array<string, mixed>|array<string, array>
     * @throws GuzzleException
     * @throws Exception
     */
    private function callPersonAPI(string $uinfin, string $accessToken): array
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

    private function newCallPersonAPI(string $uinfin, string $accessToken, JWK $sessionEphemeralKeyPair): array
    {
        $urlLink = config('laravel-myinfo-sg.api_person_url')."/{$uinfin}/";

        $headers = [
            'Cache-Control' => 'no-cache',
        ];

        $params = [
            'scope' => urlencode(config('laravel-myinfo-sg.scope')),
        ];


    }

    /**
     * Create Person Request.
     *
     * @throws Exception|GuzzleException
     */
    private function createPersonRequest(string $sub, string $validAccessToken): ResponseInterface
    {
        $guzzleClient = new Client;

        $url = config('laravel-myinfo-sg.api_person_url')."/{$sub}/";

        $params = [
            'client_id' => $this->clientId,
            'scope' => $this->scope,
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

    private function getPersonData(string $accessToken, JWK $sessionEphemeralKeyPair, array $privateEncryptionKeys)
    {
        $response = $this->getPersonDataWithToken(
            $accessToken,
            $sessionEphemeralKeyPair,
            $privateEncryptionKeys,
        );

        return $response;
    }


    public function setScope(array|string $scope): static
    {
        if (is_string($scope)) {
            $this->scope = $scope;
        } else {
            $this->scope = join(' ', $scope);
        }

        return $this;
    }

    public function setRedirectUri(string $redirectUri): static
    {
        $this->redirectUri = $redirectUri;
        return $this;
    }

    private function getPersonDataWithToken(string $accessToken, JWK $sessionEphemeralKeyPair, array $privateEncryptionKeys): array
    {
        $decodedToken = MyinfoSecurityService::newVerifyJWS(
            $accessToken,
            config('laravel-myinfo-sg.api_myinfo_jwks_url'),
        );

        if ($decodedToken === null) {
            throw new InvalidAccessTokenException;
        }

        $uinfin = $decodedToken['sub'];

        if ($uinfin === null) {
            throw new SubNotFoundException;
        }

        $personResponse = $this->newCallPersonAPI(
            $uinfin,
            $accessToken,
            $sessionEphemeralKeyPair,
        );

        return $personResponse;


    }
}
