<?php

namespace Ziming\LaravelMyinfoSg;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Ziming\LaravelMyinfoSg\Exceptions\AccessTokenNotFoundException;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidAccessTokenException;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidDataOrSignatureForPersonDataException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoPersonDataNotFoundException;
use Ziming\LaravelMyinfoSg\Exceptions\SubNotFoundException;
use Ziming\LaravelMyinfoSg\Services\MyinfoSecurityService;

class LaravelMyinfoSg
{
    /**
     * Generate MyInfo Authorise API URI to redirect to.
     * @return string
     * @throws \Exception
     */
    public function generateAuthoriseApiUrl() : string
    {
        $query = http_build_query([
            'client_id' => config('laravel-myinfo-sg.client_id'),
            'attributes' => config('laravel-myinfo-sg.attributes'),
            'purpose' => config('laravel-myinfo-sg.purpose'),
            'state' => random_int(PHP_INT_MIN, PHP_INT_MAX),
            'redirect_uri' => config('laravel-myinfo-sg.redirect_url'),
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
     * @param Request $request
     * @return array The Person Data
     * @throws \Exception
     */
    public function getMyinfoPersonData(Request $request)
    {
        $code = $request->input('code');

        $tokenRequestResponse = $this->createTokenRequest($code);

        $tokenRequestResponseBody = $tokenRequestResponse->getBody();

        if ($tokenRequestResponseBody) {
            $decoded = json_decode($tokenRequestResponseBody, true);

            if ($decoded) {
                return $this->callPersonAPI($decoded['access_token']);
            }
        }

        throw new AccessTokenNotFoundException;
    }

    /**
     * Create Token Request.
     *
     * @param string $code
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \Exception
     */
    private function createTokenRequest(string $code)
    {
        $guzzleClient = new Client;

        $contentType = 'application/x-www-form-urlencoded';
        $method = 'POST';

        $params = [
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('laravel-myinfo-sg.redirect_url'),
            'client_id' => config('laravel-myinfo-sg.client_id'),
            'client_secret' => config('laravel-myinfo-sg.client_secret'),
            'code' => $code,
        ];

        $headers = [
            'Cache-Control' => 'no-cache',
            'Content-Type' => $contentType,
            'Accept-Encoding' => 'gzip',
        ];

        if (config('laravel-myinfo-sg.auth_level') === 'L2') {
            $authHeaders = MyinfoSecurityService::generateAuthorizationHeader(
                config('laravel-myinfo-sg.api_token_url'),
                $params,
                $method,
                $contentType,
                config('laravel-myinfo-sg.auth_level'),
                config('laravel-myinfo-sg.client_id'),
                config('laravel-myinfo-sg.client_secret')
            );

            $headers['Authorization'] = $authHeaders;
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
     * @param $accessToken
     * @return array
     * @throws \Exception
     */
    private function callPersonAPI($accessToken)
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
                    config('laravel-myinfo-sg.private_key_path')
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
     * @param $sub
     * @param $validAccessToken
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \Exception
     */
    private function createPersonRequest($sub, $validAccessToken)
    {
        $guzzleClient = new Client;

        $url = config('laravel-myinfo-sg.api_person_url')."/{$sub}/";

        $params = [
            'client_id' => config('laravel-myinfo-sg.client_id'),
            'attributes' => config('laravel-myinfo-sg.attributes'),
        ];

        $headers = [
            'Cache-Control' => 'no-cache',
            'Accept-Encoding' => 'gzip',
        ];

        $authHeaders = MyInfoSecurityService::generateAuthorizationHeader(
            $url,
            $params,
            'GET',
            '',
            config('laravel-myinfo-sg.auth_level'),
            config('laravel-myinfo-sg.client_id'),
            config('laravel-myinfo-sg.client_secret')
        );

        if ($authHeaders) {
            $headers['Authorization'] = $authHeaders.',Bearer '.$validAccessToken;
        } else {
            $headers['Authorization'] = 'Bearer '.$validAccessToken;
        }

        $response = $guzzleClient->get($url, [
            'query' => $params,
            'headers' => $headers,
        ]);

        return $response;
    }
}
