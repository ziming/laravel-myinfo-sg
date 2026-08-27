<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Responses;

use Illuminate\Support\Arr;
use Jose\Component\Core\JWKSet;
use RuntimeException;
use Saloon\Http\Response;
use Throwable;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidUserInfoException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassJwksRequest;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests\GetSingpassOpenIdConfigurationRequest;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\JwkSetValidator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\UserInfoProcessor;

class GetUserResponse extends Response
{
    /**
     * Low-level compatibility decoder.
     *
     * This validates the signed UserInfo claim set, but cannot bind its subject to
     * an ID token because getUser(string) does not carry a VerifiedTokenSet.
     * The mixed parameter and return types must match Saloon Response::json(): a
     * requested key may resolve to any JSON value or to the caller's default.
     */
    public function json(string|int|null $key = null, mixed $default = null): mixed
    {
        $compactUserInfo = $this->validatedCompactBody();
        $metadata = $this->discoveryMetadata();
        $issuer = self::resolveExpectedIssuer($metadata);
        $jwksUri = $metadata['jwks_uri'];
        $clientId = config('laravel-myinfo-sg-v6.client_id');

        if (
            $jwksUri === ''
            || filter_var($jwksUri, FILTER_VALIDATE_URL) === false
            || parse_url($jwksUri, PHP_URL_SCHEME) !== 'https'
            || ! is_string($clientId)
            || $clientId === ''
        ) {
            throw new InvalidUserInfoException('The UserInfo verification configuration is invalid.');
        }

        try {
            $privateDecryptionJwks = (new JwkSetValidator)->validatePrivateJwks(
                config('laravel-myinfo-sg-v6.private_jwks'),
            );
        } catch (Throwable) {
            throw new InvalidUserInfoException('The UserInfo decryption keys are invalid.');
        }

        $userInfo = (new UserInfoProcessor)->processUnbound(
            $compactUserInfo,
            $privateDecryptionJwks,
            $this->fetchSingpassPublicJwks($jwksUri),
            $issuer,
            $clientId,
        );

        return Arr::get($userInfo->claims(), $key, $default);
    }

    private function validatedCompactBody(): string
    {
        $body = $this->body();
        $error = $this->decodeJsonObject($body);

        if (
            ! $this->successful()
            || ($error !== null && array_key_exists('error', $error))
            || trim($body) === ''
        ) {
            throw new InvalidUserInfoException('The authorization provider returned an invalid UserInfo response.');
        }

        return $body;
    }

    /** @return array{issuer?: string, jwks_uri: string} */
    private function discoveryMetadata(): array
    {
        $response = (new GetSingpassOpenIdConfigurationRequest)->send();

        if (! $response->successful()) {
            throw new InvalidUserInfoException('The Singpass discovery metadata could not be loaded.');
        }

        $metadata = $this->decodeJsonObject($response->body());

        if ($metadata === null) {
            throw new InvalidUserInfoException('The Singpass discovery metadata is invalid.');
        }

        $jwksUri = $metadata['jwks_uri'] ?? null;

        if (! is_string($jwksUri) || $jwksUri === '') {
            throw new InvalidUserInfoException('The Singpass discovery metadata is invalid.');
        }

        $validatedMetadata = ['jwks_uri' => $jwksUri];
        $issuer = $metadata['issuer'] ?? null;

        if (is_string($issuer) && $issuer !== '') {
            $validatedMetadata['issuer'] = $issuer;
        }

        return $validatedMetadata;
    }

    /**
     * @param array{issuer?: string, jwks_uri: string} $configData
     */
    private static function resolveExpectedIssuer(array $configData): string
    {
        if (isset($configData['issuer']) && $configData['issuer'] !== '') {
            return $configData['issuer'];
        }

        $issuerUri = config('laravel-myinfo-sg-v6.issuer_uri');

        if (! is_string($issuerUri) || $issuerUri === '') {
            throw new InvalidUserInfoException('The UserInfo issuer is not configured.');
        }

        return rtrim($issuerUri, '/').'/fapi';
    }

    private function fetchSingpassPublicJwks(string $jwksUri): JWKSet
    {
        $response = (new GetSingpassJwksRequest($jwksUri))->send();

        if (! $response->successful()) {
            throw new InvalidUserInfoException('The Singpass signing keys could not be loaded.');
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
            throw new InvalidUserInfoException('The Singpass signing keys are invalid.');
        }
    }

    /**
     * This is the raw JSON decoding boundary. Values remain mixed until the
     * endpoint-specific validation above narrows the fields it consumes.
     *
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
}
