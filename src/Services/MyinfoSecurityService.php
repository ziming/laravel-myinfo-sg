<?php

namespace Ziming\LaravelMyinfoSg\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP;
use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\Compression\Deflate;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;

/**
 * @internal
 */
final class MyinfoSecurityService
{
    /**
     * Verify JWS.
     *
     * @throws Exception
     */
    public static function verifyJWS(string $accessToken): ?array
    {
        $algorithmManager = new AlgorithmManager([new RS256]);

        if (config('laravel-myinfo-sg.public_cert_content')) {
            $jwk = JWKFactory::createFromKey(config('laravel-myinfo-sg.public_cert_content'));
        } else {
            $jwk = JWKFactory::createFromCertificateFile(config('laravel-myinfo-sg.public_cert_path'));
        }

        $jwsVerifier = new JWSVerifier($algorithmManager);
        $serializerManager = new JWSSerializerManager([new CompactSerializer]);

        $jws = $serializerManager->unserialize($accessToken);
        $verified = $jwsVerifier->verifyWithKey($jws, $jwk, 0);

        return $verified ? json_decode($jws->getPayload(), true) : null;
    }

    /**
     * Generate Authorization Header.
     *
     * @throws Exception
     */
    public static function generateAuthorizationHeader(string $url, array $params, string $method, string $contentType,
                                                       string $authType, string $appId,
                                                       string $passphrase): string
    {
        if ($authType === 'L2') {
            return self::generateSHA256withRSAHeader($url, $params, $method, $contentType, $appId, $passphrase);
        }

        return '';
    }

    /**
     * Generate SHA256 with RSA Header.
     *
     * @throws Exception
     */
    private static function generateSHA256withRSAHeader(string $url, array $params, string $method, string $contentType, string $appId, string $passphrase): string
    {
        $nonce = random_int(PHP_INT_MIN, PHP_INT_MAX);

        $timestamp = (int) round(microtime(true) * 1000);

        $defaultApexHeaders = [
            'app_id'           => $appId,
            'nonce'            => $nonce,
            'signature_method' => 'RS256',
            'timestamp'        => $timestamp,
        ];

        if ($method === 'POST' && $contentType !== 'application/x-www-form-urlencoded') {
            $params = [];
        }

        $baseParams = array_merge($defaultApexHeaders, $params);
        ksort($baseParams);

        $baseParamsStr = http_build_query($baseParams);
        $baseParamsStr = urldecode($baseParamsStr);

        $baseString = "{$method}&{$url}&{$baseParamsStr}";

        if (config('laravel-myinfo-sg.debug_mode')) {
            Log::debug('Base String (Pre Signing): '.$baseString);
        }

        if (config('laravel-myinfo-sg.private_key_content')) {
            $privateKey = openssl_pkey_get_private(config('laravel-myinfo-sg.private_key_content'), $passphrase);
        } else {
            $privateKey = openssl_pkey_get_private(config('laravel-myinfo-sg.private_key_path'), $passphrase);
        }

        openssl_sign($baseString, $signature, $privateKey, 'sha256WithRSAEncryption');

        $signature = base64_encode($signature);

        $strApexHeader = 'PKI_SIGN timestamp="'.$timestamp.
            '",nonce="'.$nonce.
            '",app_id="'.$appId.
            '",signature_method="RS256"'.
            ',signature="'.$signature.
            '"';

        return $strApexHeader;
    }

    /**
     * Decrypt JWE
     *
     * @throws Exception
     */
    public static function decryptJWE(string $personDataToken, string $passphrase = null): array|string
    {
        // $passphrase is by default null for backward compatibility purpose as I want to avoid a major version bump
        $passphrase = ($passphrase === null) ? config('laravel-myinfo-sg.client_secret') : $passphrase;

        if (config('laravel-myinfo-sg.private_key_content')) {
            $jwk = JWKFactory::createFromKey(
                config('laravel-myinfo-sg.private_key_content'),
                $passphrase
            );
        } else {
            $jwk = JWKFactory::createFromKeyFile(
                config('laravel-myinfo-sg.private_key_path'),
                $passphrase
            );
        }


        $serializerManager = new JWESerializerManager([
            new \Jose\Component\Encryption\Serializer\CompactSerializer,
        ]);

        $jwe = $serializerManager->unserialize($personDataToken);

        $keyEncryptionAlgorithmManager = new AlgorithmManager([new RSAOAEP]);

        $contentEncryptionAlgorithmManager = new AlgorithmManager([new A256GCM]);

        $compressionMethodManager = new CompressionMethodManager([new Deflate]);

        $jweDecrypter = new JWEDecrypter(
            $keyEncryptionAlgorithmManager,
            $contentEncryptionAlgorithmManager,
            $compressionMethodManager
        );

        $recipient = 0;

        $jweDecrypter->decryptUsingKey($jwe, $jwk, $recipient);

        $payload = $jwe->getPayload();

        $payload = str_replace('"', '', $payload);

        return $payload;
    }

    public function generateSessionKeyPair(): JWK
    {
        // https://github.com/singpass/myinfo-connector-v4-nodejs/blob/main/lib/securityHelper.js

        return JWKFactory::createECKey('P-256');
    }

    public function generateClientAssertion(string $url, string $clientId, string $privateSigningKey, string $jktThumbprint, string $kid): void
    {
        // https://github.com/singpass/myinfo-connector-v4-nodejs/blob/main/lib/securityHelper.js

        $now = (int) round(microtime(true) * 1000);

        $payload = [
            'sub' => $clientId,
            'jti' => Str::random(40), // generate unique random string on every client_assertion for jti
            'aud' => $url,
            'iss' => $clientId,
            'iat' => $now,
            'exp' => $now + 300, // expiry of client_assertion set to 5 mins max
            'cnf' => [
                'jkt' => $jktThumbprint // jkt thumbprint should match DPoP JWK used in the same request
            ]
        ];

        // to continue
        // $jwsKey = jose.JWK.asKey($privateSigningKey, 'pem');
        $jwsKey = JWKFactory::createFromKey($privateSigningKey);


    }

    public function generateDpop(string $url, string $ath, string $method, JWK $sessionEphemeralKeyPair): string
    {
        // https://github.com/singpass/myinfo-connector-v4-nodejs/blob/main/lib/securityHelper.js
        $now = (int) round(microtime(true) * 1000);

        $payload = [
            'htu' => $url,
            'htm' => $method,
            'jti' => Str::random(40), // on every client_assertion for jti
            'iat' => $now,
            'exp' => $now + 120, // 2 mins max
        ];

        // append ath if passed in required for /person call
        if ($ath) {
            $payload['ath'] = $ath;
        }

        // If the key is a private key (RSA, EC, OKP), it can be converted into public
        $publicKey = $sessionEphemeralKeyPair->toPublic();

        $algorithmManager = new AlgorithmManager([new ES256]);

        // $jws = $serializerManager->unserialize($accessToken);
        $jwsBuilder = new JWSBuilder($algorithmManager);
        $jws = $jwsBuilder
            ->create()
            ->withPayload(json_encode($payload))
            ->addSignature($publicKey, ['alg' => 'ES256'])
            ->build();

        $serializer = new CompactSerializer();

        $jwtToken = $serializer->serialize($jws, 0);

        if (config('laravel-myinfo-sg.debug_mode')) {
            Log::info('Encoded DPoP: ' . $jwtToken);
        }

        return $jwtToken;
    }
}
