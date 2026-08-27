<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\VerifiedUserInfo;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\AuthorizationResponseException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidIdTokenException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidUserInfoException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\SigningKeyRefreshRequiredException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetAccessTokenRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassJwksRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetUserRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\PushedAuthorizationRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses\GetUserResponse;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationCallbackValidator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\AuthorizationTransactionStore;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\DPoPProofGenerator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\IdTokenProcessor;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\JwkSetValidator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\SingpassAlgorithmProfile;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\UserInfoProcessor;

class MyinfoConnector extends Connector
{
    /**
     * @throws \JsonException
     */
    public function generateAuthorizationUrl(?string $redirectUri = null): string
    {
        $dpopSigningAlg = $this->configuredDpopSigningAlgorithm();
        $metadata = $this->getValidatedDiscoveryMetadata($dpopSigningAlg, false);
        $effectiveRedirectUri = $redirectUri ?? config('laravel-myinfo-sg-v6.redirect_uri');

        if (! is_string($effectiveRedirectUri) || $effectiveRedirectUri === '') {
            throw new RuntimeException('MyInfo V6 redirect URI is not configured.');
        }

        $codeVerifier = Str::random(128);
        $encoded = base64_encode(hash('sha256', $codeVerifier, true));
        $codeChallenge = strtr(rtrim($encoded, '='), '+/', '-_');

        $state = Str::random(40);
        $nonce = (string) Str::uuid();
        $dpopPrivateJwk = $this->createDpopPrivateKey($dpopSigningAlg);
        $transaction = new AuthorizationTransaction(
            $state,
            $nonce,
            $codeVerifier,
            $effectiveRedirectUri,
            $metadata['issuer'],
            json_encode($dpopPrivateJwk, JSON_THROW_ON_ERROR),
            CarbonImmutable::now()->timestamp,
            $dpopSigningAlg,
        );

        // Call PAR endpoint
        $parRequest = new PushedAuthorizationRequest(
            $metadata['pushed_authorization_request_endpoint'],
            $transaction->issuer,
            $dpopPrivateJwk,
            $transaction->state,
            $transaction->nonce,
            $codeChallenge,
            $transaction->redirectUri,
        );
        $parResponse = (new MyinfoV6RequestSender)->send($parRequest, 'par', true, $this);
        $parData = $this->decodeJsonObject($parResponse->body());

        if (! $parResponse->successful() || $parData === null) {
            throw new RuntimeException('The pushed authorization request was rejected.');
        }

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
        $latestState = session()->pull(config('laravel-myinfo-sg-v6.state_session_key'));

        if (is_string($latestState) && $latestState !== '') {
            $transaction = $this->transactionStore()->pull($latestState);

            if ($transaction !== null) {
                $metadata = $this->getValidatedDiscoveryMetadata($transaction->dpopSigningAlg, true);
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

        [$dpopPrivateJwk] = $this->getStoredDpopKeyPair();
        $metadata = $this->getValidatedDiscoveryMetadata(
            DPoPProofGenerator::signingAlgorithm($dpopPrivateJwk),
            true,
        );
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
        );
        $response = (new MyinfoV6RequestSender)->send(
            $getAccessTokenRequest,
            'token',
            true,
            $this,
        );

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
        $metadata = $this->getValidatedDiscoveryMetadata(
            $callback->transaction->dpopSigningAlg,
            true,
        );

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
        try {
            $privateDecryptionJwks = (new JwkSetValidator)->validatePrivateJwks(
                config('laravel-myinfo-sg-v6.private_jwks'),
            );
        } catch (Throwable) {
            throw new InvalidIdTokenException('The ID token decryption keys are invalid.');
        }

        $singpassPublicJwks = $this->fetchSingpassPublicJwks(
            $metadata['jwks_uri'],
            true,
            InvalidIdTokenException::class,
        );

        $claims = $this->processIdTokenWithJwksRefresh(
            $tokenData['id_token'],
            $privateDecryptionJwks,
            $singpassPublicJwks,
            $metadata['jwks_uri'],
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
        $metadata = $this->getValidatedDiscoveryMetadata(
            $callback->transaction->dpopSigningAlg,
            true,
        );

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
     * Low-level compatibility path without ID-token-to-UserInfo subject binding.
     *
     * Prefer getVerifiedUserInfo() for new integrations.
     *
     * @throws \JsonException
     */
    public function getUser(string $accessToken): GetUserResponse
    {
        [$dpopPrivateJwk] = $this->getStoredDpopKeyPair();
        $metadata = $this->getValidatedDiscoveryMetadata(
            DPoPProofGenerator::signingAlgorithm($dpopPrivateJwk),
            false,
        );

        /** @var GetUserResponse $response */
        $response = (new MyinfoV6RequestSender)->sendWithRequestFactory(
            fn (): GetUserRequest => new GetUserRequest(
                $metadata['userinfo_endpoint'],
                $accessToken,
                $dpopPrivateJwk,
            ),
            'userinfo',
            false,
            $this,
        );

        return $response;
    }

    /**
     * Fetch, decrypt, verify, and bind UserInfo to the verified ID-token subject.
     */
    public function getVerifiedUserInfo(VerifiedTokenSet $tokenSet): VerifiedUserInfo
    {
        $metadata = $this->getValidatedDiscoveryMetadata(
            $tokenSet->dpopSigningAlgorithm(),
            false,
        );
        $clientId = config('laravel-myinfo-sg-v6.client_id');

        if (! is_string($clientId) || $clientId === '') {
            throw new InvalidUserInfoException('MyInfo V6 client ID is not configured.');
        }

        $response = (new MyinfoV6RequestSender)->sendWithRequestFactory(
            fn (): GetUserRequest => GetUserRequest::withDpopProofFactory(
                $metadata['userinfo_endpoint'],
                $tokenSet->accessToken(),
                fn (): string => $tokenSet->createUserInfoDpopProof($metadata['userinfo_endpoint']),
            ),
            'userinfo',
            false,
            $this,
        );
        $compactUserInfo = $this->validateUserInfoResponse($response);

        try {
            $privateDecryptionJwks = (new JwkSetValidator)->validatePrivateJwks(
                config('laravel-myinfo-sg-v6.private_jwks'),
            );
        } catch (Throwable) {
            throw new InvalidUserInfoException('The UserInfo verification keys are invalid.');
        }

        $singpassPublicJwks = $this->fetchSingpassPublicJwks(
            $metadata['jwks_uri'],
            false,
            InvalidUserInfoException::class,
        );

        return $this->processUserInfoWithJwksRefresh(
            $compactUserInfo,
            $privateDecryptionJwks,
            $singpassPublicJwks,
            $metadata['jwks_uri'],
            $metadata['issuer'],
            $clientId,
            $tokenSet->subject(),
        );
    }

    public function resolveBaseUrl(): string
    {
        return config('laravel-myinfo-sg-v6.issuer_uri');
    }

    private function createDpopPrivateKey(string $algorithm): JWK
    {
        return JWKFactory::createECKey(SingpassAlgorithmProfile::dpopCurve($algorithm), [
            'alg' => $algorithm,
            'use' => 'sig',
        ]);
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

        DPoPProofGenerator::signingAlgorithm($privateJwk);

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
    private function getValidatedDiscoveryMetadata(
        string $dpopSigningAlg,
        bool $restartAuthorization,
    ): array
    {
        $this->assertLocallySupportedDpopAlgorithm($dpopSigningAlg);
        $response = (new MyinfoV6RequestSender)->send(
            new GetSingpassOpenIdConfigurationRequest,
            'discovery',
            $restartAuthorization,
            $this,
        );
        $metadata = $this->decodeJsonObject($response->body());

        if (! $response->successful() || $metadata === null) {
            throw new RuntimeException('The Singpass discovery metadata could not be loaded.');
        }

        $issuerUri = config('laravel-myinfo-sg-v6.issuer_uri');

        if (! is_string($issuerUri) || $issuerUri === '') {
            throw new RuntimeException('MyInfo V6 issuer URI is not configured.');
        }

        $expectedIssuer = rtrim($issuerUri, '/').'/fapi';
        $issuer = $this->requiredMetadataUrl($metadata, 'issuer');

        if (! hash_equals($expectedIssuer, $issuer)) {
            throw new RuntimeException('Singpass discovery issuer does not match the configured issuer.');
        }

        $advertisedDpopAlgorithms = $metadata['dpop_signing_alg_values_supported'] ?? null;

        if (
            ! is_array($advertisedDpopAlgorithms)
            || ! in_array($dpopSigningAlg, $advertisedDpopAlgorithms, true)
        ) {
            throw new RuntimeException('Singpass discovery is incompatible with the selected DPoP signing algorithm.');
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

    private function configuredDpopSigningAlgorithm(): string
    {
        $algorithm = config('laravel-myinfo-sg-v6.dpop_signing_alg', 'ES256');

        if (! is_string($algorithm) || $algorithm === '') {
            throw new RuntimeException('MyInfo V6 DPoP signing algorithm is invalid.');
        }

        $this->assertLocallySupportedDpopAlgorithm($algorithm);

        return $algorithm;
    }

    private function assertLocallySupportedDpopAlgorithm(string $algorithm): void
    {
        try {
            SingpassAlgorithmProfile::dpopCurve($algorithm);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('MyInfo V6 DPoP signing algorithm is invalid.');
        }
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
        );

        return (new MyinfoV6RequestSender)->send($request, 'token', true, $this);
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

    private function validateUserInfoResponse(Response $response): string
    {
        $body = $response->body();
        $data = $this->decodeJsonObject($body);

        if (
            ! $response->successful()
            || ($data !== null && array_key_exists('error', $data))
            || trim($body) === ''
        ) {
            throw new InvalidUserInfoException('The authorization provider returned an invalid UserInfo response.');
        }

        return $body;
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

    /**
     * @param class-string<InvalidIdTokenException|InvalidUserInfoException> $invalidException
     */
    private function fetchSingpassPublicJwks(
        string $jwksUri,
        bool $restartAuthorization,
        string $invalidException,
        bool $invalidateCache = false,
    ): JWKSet
    {
        $request = new GetSingpassJwksRequest($jwksUri);

        if ($invalidateCache) {
            $request->invalidateCache();
        }

        $response = (new MyinfoV6RequestSender)->send(
            $request,
            'jwks',
            $restartAuthorization,
            $this,
        );

        if (! $response->successful()) {
            throw new $invalidException('The Singpass signing keys could not be loaded.');
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
            throw new $invalidException('The Singpass signing keys are invalid.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function processIdTokenWithJwksRefresh(
        #[\SensitiveParameter] string $idToken,
        JWKSet $privateDecryptionJwks,
        JWKSet $singpassPublicJwks,
        string $jwksUri,
        string $expectedIssuer,
        string $clientId,
        string $expectedNonce,
    ): array {
        $processor = new IdTokenProcessor;

        try {
            return $processor->process(
                $idToken,
                $privateDecryptionJwks,
                $singpassPublicJwks,
                $expectedIssuer,
                $clientId,
                $expectedNonce,
            );
        } catch (SigningKeyRefreshRequiredException) {
            $refreshedJwks = $this->fetchSingpassPublicJwks(
                $jwksUri,
                true,
                InvalidIdTokenException::class,
                true,
            );

            try {
                return $processor->process(
                    $idToken,
                    $privateDecryptionJwks,
                    $refreshedJwks,
                    $expectedIssuer,
                    $clientId,
                    $expectedNonce,
                );
            } catch (Throwable) {
                throw new InvalidIdTokenException('The ID token is invalid.');
            }
        }
    }

    private function processUserInfoWithJwksRefresh(
        #[\SensitiveParameter] string $compactUserInfo,
        JWKSet $privateDecryptionJwks,
        JWKSet $singpassPublicJwks,
        string $jwksUri,
        string $expectedIssuer,
        string $clientId,
        string $expectedSubject,
    ): VerifiedUserInfo {
        $processor = new UserInfoProcessor;

        try {
            return $processor->process(
                $compactUserInfo,
                $privateDecryptionJwks,
                $singpassPublicJwks,
                $expectedIssuer,
                $clientId,
                $expectedSubject,
            );
        } catch (SigningKeyRefreshRequiredException) {
            $refreshedJwks = $this->fetchSingpassPublicJwks(
                $jwksUri,
                false,
                InvalidUserInfoException::class,
                true,
            );

            try {
                return $processor->process(
                    $compactUserInfo,
                    $privateDecryptionJwks,
                    $refreshedJwks,
                    $expectedIssuer,
                    $clientId,
                    $expectedSubject,
                );
            } catch (Throwable) {
                throw new InvalidUserInfoException('The UserInfo response is invalid.');
            }
        }
    }
}
