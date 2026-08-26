<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use RuntimeException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Throwable;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\AuthorizationTransaction;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\ValidatedAuthorizationCallback;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\VerifiedTokenSet;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\AuthorizationResponseException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidIdTokenException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassJwksRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetUserRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\PushedAuthorizationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses\GetUserResponse;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationCallbackValidator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationTransactionStore;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\IdTokenProcessor;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\JwkSetValidator;

class MyinfoConnector extends Connector
{
    /**
     * @throws \JsonException
     */
    public function generateAuthorizationUrl(?string $redirectUri = null): string
    {
        $metadata = $this->getValidatedDiscoveryMetadata();
        $effectiveRedirectUri = $redirectUri ?? config('laravel-myinfo-sg-v6.redirect_uri');

        if (! is_string($effectiveRedirectUri) || $effectiveRedirectUri === '') {
            throw new RuntimeException('MyInfo V6 redirect URI is not configured.');
        }

        $codeVerifier = Str::random(128);
        $encoded = base64_encode(hash('sha256', $codeVerifier, true));
        $codeChallenge = strtr(rtrim($encoded, '='), '+/', '-_');

        $state = Str::random(40);
        $nonce = (string) Str::uuid();
        [$dpopPrivateJwk, $dpopPublicJwk] = $this->createDpopKeyPair();
        $transaction = new AuthorizationTransaction(
            $state,
            $nonce,
            $codeVerifier,
            $effectiveRedirectUri,
            $metadata['issuer'],
            json_encode($dpopPrivateJwk, JSON_THROW_ON_ERROR),
            CarbonImmutable::now()->timestamp,
        );

        // Call PAR endpoint
        $parRequest = new PushedAuthorizationRequest(
            $metadata['pushed_authorization_request_endpoint'],
            $transaction->issuer,
            $dpopPrivateJwk,
            $dpopPublicJwk,
            $transaction->state,
            $transaction->nonce,
            $codeChallenge,
            $transaction->redirectUri,
        );
        $parResponse = $parRequest->send();
        $parData = $parResponse->json();
        $requestUri = $parData['request_uri'] ?? null;

        if (! is_string($requestUri) || $requestUri === '') {
            throw new RuntimeException('The pushed authorization response did not contain a request URI.');
        }

        $this->transactionStore()->put($transaction);
        session()->put(config('laravel-myinfo-sg-v6.state_session_key'), $transaction->state);

        // Build the authorization URL with only client_id and request_uri
        $authorizationUrl = $metadata['authorization_endpoint'].'?'.http_build_query([
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
     * Low-level token exchange that does not validate callback state or issuer.
     *
     * Prefer completeAuthorization() for new integrations.
     *
     * @throws \JsonException
     */
    public function getAccessToken(string $code): array
    {
        $metadata = $this->getValidatedDiscoveryMetadata();
        $latestState = session()->pull(config('laravel-myinfo-sg-v6.state_session_key'));

        if (is_string($latestState) && $latestState !== '') {
            $transaction = $this->transactionStore()->pull($latestState);

            if ($transaction !== null) {
                $dpopPrivateJwk = $transaction->dpopPrivateJwk();
                $response = $this->sendAccessTokenRequest(
                    $metadata['token_endpoint'],
                    $code,
                    $transaction->issuer,
                    $transaction->redirectUri,
                    $transaction->codeVerifier,
                    $dpopPrivateJwk,
                );

                // Transfer ownership to the legacy UserInfo path only after the
                // transaction record has been consumed successfully.
                session()->put(
                    config('laravel-myinfo-sg-v6.dpop_private_jwk_session_key'),
                    json_encode($dpopPrivateJwk, JSON_THROW_ON_ERROR),
                );

                return $response;
            }
        }

        [$dpopPrivateJwk, $dpopPublicJwk] = $this->getStoredDpopKeyPair();
        $redirectUri = session(
            config('laravel-myinfo-sg-v6.redirect_uri_session_key'),
            config('laravel-myinfo-sg-v6.redirect_uri')
        );
        $codeVerifier = session(config('laravel-myinfo-sg-v6.code_verifier_session_key'));

        if (! is_string($redirectUri) || $redirectUri === '') {
            throw new RuntimeException('No redirect URI found for low-level token exchange.');
        }

        if (! is_string($codeVerifier) || $codeVerifier === '') {
            throw new RuntimeException('No PKCE code verifier found for low-level token exchange.');
        }

        $getAccessTokenRequest = new GetAccessTokenRequest(
            $metadata['token_endpoint'],
            $code,
            $metadata['issuer'],
            $redirectUri,
            $codeVerifier,
            $dpopPrivateJwk,
            $dpopPublicJwk,
        );
        $response = $getAccessTokenRequest->send();

        return $response->json();
    }

    public function validateAuthorizationCallback(Request $request): ValidatedAuthorizationCallback
    {
        return (new AuthorizationCallbackValidator(
            new AuthorizationTransactionStore($request->session()),
        ))->validate($request);
    }

    /**
     * Complete the callback, token exchange, and ID-token verification boundary.
     */
    public function completeAuthorization(Request $request): VerifiedTokenSet
    {
        $callback = $this->validateAuthorizationCallback($request);
        $metadata = $this->getValidatedDiscoveryMetadata();

        if (! hash_equals($callback->transaction->issuer, $metadata['issuer'])) {
            throw new RuntimeException('The discovery issuer changed during authorization.');
        }

        $clientId = config('laravel-myinfo-sg-v6.client_id');

        if (! is_string($clientId) || $clientId === '') {
            throw new RuntimeException('MyInfo V6 client ID is not configured.');
        }

        $dpopPrivateJwk = $callback->transaction->dpopPrivateJwk();
        $tokenResponse = $this->sendAccessTokenResponse(
            $metadata['token_endpoint'],
            $callback->code,
            $callback->transaction->issuer,
            $callback->transaction->redirectUri,
            $callback->transaction->codeVerifier,
            $dpopPrivateJwk,
        );
        $tokenData = $this->validateTokenResponse($tokenResponse);
        $singpassPublicJwks = $this->fetchSingpassPublicJwks($metadata['jwks_uri']);

        try {
            $privateDecryptionJwks = (new JwkSetValidator)->validatePrivateJwks(
                config('laravel-myinfo-sg-v6.private_jwks'),
            );
        } catch (Throwable) {
            throw new InvalidIdTokenException('The ID token decryption keys are invalid.');
        }

        $claims = (new IdTokenProcessor)->process(
            $tokenData['id_token'],
            $privateDecryptionJwks,
            $singpassPublicJwks,
            $callback->transaction->issuer,
            $clientId,
            $callback->transaction->nonce,
        );

        return new VerifiedTokenSet(
            $tokenData['access_token'],
            $claims,
            $tokenData['token_type'],
            $dpopPrivateJwk,
        );
    }

    /**
     * Exchange a previously validated callback for a raw, unverified token response.
     *
     * ID-token validation is required before the authorization flow is complete.
     *
     * @return array<string, mixed>
     * @throws \JsonException
     */
    public function getAccessTokenFromValidatedCallback(ValidatedAuthorizationCallback $callback): array
    {
        $metadata = $this->getValidatedDiscoveryMetadata();

        if (! hash_equals($callback->transaction->issuer, $metadata['issuer'])) {
            throw new RuntimeException('The discovery issuer changed during authorization.');
        }

        $dpopPrivateJwk = $callback->transaction->dpopPrivateJwk();

        return $this->sendAccessTokenRequest(
            $metadata['token_endpoint'],
            $callback->code,
            $callback->transaction->issuer,
            $callback->transaction->redirectUri,
            $callback->transaction->codeVerifier,
            $dpopPrivateJwk,
        );
    }

    /**
     * @throws \JsonException
     */
    public function getUser(string $accessToken): GetUserResponse
    {
        $metadata = $this->getValidatedDiscoveryMetadata();
        [$dpopPrivateJwk, $dpopPublicJwk] = $this->getStoredDpopKeyPair();

        $getUserRequest = new GetUserRequest(
            $metadata['userinfo_endpoint'],
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
     */
    private function createDpopKeyPair(): array
    {
        $privateJwk = JWKFactory::createECKey('P-256', [
            'alg' => 'ES256',
            'use' => 'sig',
        ]);

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

    /**
     * @return array{
     *     issuer: string,
     *     authorization_endpoint: string,
     *     pushed_authorization_request_endpoint: string,
     *     token_endpoint: string,
     *     userinfo_endpoint: string,
     *     jwks_uri: string
     * }
     */
    private function getValidatedDiscoveryMetadata(): array
    {
        $response = (new GetSingpassOpenIdConfigurationRequest)->send();
        $metadata = $response->json();

        $issuerUri = config('laravel-myinfo-sg-v6.issuer_uri');

        if (! is_string($issuerUri) || $issuerUri === '') {
            throw new RuntimeException('MyInfo V6 issuer URI is not configured.');
        }

        $expectedIssuer = rtrim($issuerUri, '/').'/fapi';
        $issuer = $this->requiredMetadataUrl($metadata, 'issuer');

        if (! hash_equals($expectedIssuer, $issuer)) {
            throw new RuntimeException('Singpass discovery issuer does not match the configured issuer.');
        }

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $this->requiredMetadataUrl($metadata, 'authorization_endpoint'),
            'pushed_authorization_request_endpoint' => $this->requiredMetadataUrl($metadata, 'pushed_authorization_request_endpoint'),
            'token_endpoint' => $this->requiredMetadataUrl($metadata, 'token_endpoint'),
            'userinfo_endpoint' => $this->requiredMetadataUrl($metadata, 'userinfo_endpoint'),
            'jwks_uri' => $this->requiredMetadataUrl($metadata, 'jwks_uri'),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function requiredMetadataUrl(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;

        if (
            ! is_string($value)
            || $value === ''
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || parse_url($value, PHP_URL_SCHEME) !== 'https'
        ) {
            throw new RuntimeException("Singpass discovery metadata [{$key}] must be a valid HTTPS URL.");
        }

        return $value;
    }

    private function transactionStore(): AuthorizationTransactionStore
    {
        $session = session()->driver();

        if (! $session instanceof Session) {
            throw new RuntimeException('A Laravel session is required for MyInfo V6 authorization.');
        }

        return new AuthorizationTransactionStore($session);
    }

    /**
     * @return array<string, mixed>
     * @throws \JsonException
     */
    private function sendAccessTokenRequest(
        string $tokenEndpoint,
        string $code,
        string $issuer,
        string $redirectUri,
        string $codeVerifier,
        JWK $dpopPrivateJwk,
    ): array {
        $data = $this->sendAccessTokenResponse(
            $tokenEndpoint,
            $code,
            $issuer,
            $redirectUri,
            $codeVerifier,
            $dpopPrivateJwk,
        )->json();

        return $data;
    }

    private function sendAccessTokenResponse(
        string $tokenEndpoint,
        string $code,
        string $issuer,
        string $redirectUri,
        string $codeVerifier,
        JWK $dpopPrivateJwk,
    ): Response {
        $request = new GetAccessTokenRequest(
            $tokenEndpoint,
            $code,
            $issuer,
            $redirectUri,
            $codeVerifier,
            $dpopPrivateJwk,
            $dpopPrivateJwk->toPublic(),
        );

        return $request->send();
    }

    /**
     * @return array{access_token: string, id_token: string, token_type: string}
     */
    private function validateTokenResponse(Response $response): array
    {
        $data = $this->decodeJsonObject($response->body());

        if (! $response->successful()) {
            throw new AuthorizationResponseException(
                $this->safeTokenErrorCode($data['error'] ?? null, 'token_endpoint_error'),
            );
        }

        if ($data === null) {
            throw new AuthorizationResponseException('invalid_token_response');
        }

        if (array_key_exists('error', $data)) {
            throw new AuthorizationResponseException(
                $this->safeTokenErrorCode($data['error'], 'token_endpoint_error'),
            );
        }

        $accessToken = $data['access_token'] ?? null;
        $idToken = $data['id_token'] ?? null;
        $tokenType = $data['token_type'] ?? null;

        if (
            ! is_string($accessToken)
            || trim($accessToken) === ''
            || ! is_string($idToken)
            || trim($idToken) === ''
            || $tokenType !== 'DPoP'
        ) {
            throw new AuthorizationResponseException('invalid_token_response');
        }

        return [
            'access_token' => $accessToken,
            'id_token' => $idToken,
            'token_type' => $tokenType,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $json): ?array
    {
        try {
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_object($object) && is_array($data) ? $data : null;
    }

    private function safeTokenErrorCode(mixed $error, string $fallback): string
    {
        if (! is_string($error) || preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D', $error) !== 1) {
            return $fallback;
        }

        return $error;
    }

    private function fetchSingpassPublicJwks(string $jwksUri): JWKSet
    {
        $response = (new GetSingpassJwksRequest($jwksUri))->send();

        if (! $response->successful()) {
            throw new InvalidIdTokenException('The Singpass signing keys could not be loaded.');
        }

        try {
            $data = $this->decodeJsonObject($response->body());
            $keys = $data['keys'] ?? null;

            if (! is_array($keys) || ! array_is_list($keys) || $keys === []) {
                throw new RuntimeException;
            }

            $seenKeyIds = [];

            foreach ($keys as $key) {
                $kid = is_array($key) ? ($key['kid'] ?? null) : null;

                if (! is_string($kid) || $kid === '' || isset($seenKeyIds[$kid])) {
                    throw new RuntimeException;
                }

                $seenKeyIds[$kid] = true;
            }

            return JWKSet::createFromKeyData($data);
        } catch (Throwable) {
            throw new InvalidIdTokenException('The Singpass signing keys are invalid.');
        }
    }
}
