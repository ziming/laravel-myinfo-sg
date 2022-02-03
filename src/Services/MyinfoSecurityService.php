<?php

namespace Ziming\LaravelMyinfoSg\Services;

use Illuminate\Support\Facades\Log;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP;
use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\Compression\Deflate;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
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
     * @throws \Exception
     */
    public static function verifyJWS(string $accessToken): ?array
    {
        $algorithmManager = new AlgorithmManager([new RS256]);
        $jwk = JWKFactory::createFromCertificateFile(config('laravel-myinfo-sg.public_cert_path'));
        $jwsVerifier = new JWSVerifier($algorithmManager);
        $serializerManager = new JWSSerializerManager([new CompactSerializer]);

        $jws = $serializerManager->unserialize($accessToken);
        $verified = $jwsVerifier->verifyWithKey($jws, $jwk, 0);

        return $verified ? json_decode($jws->getPayload(), true) : null;
    }

    /**
     * Generate Authorization Header.
     *
     * @throws \Exception
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
     * @throws \Exception
     */
    private static function generateSHA256withRSAHeader(string $url, array $params, string $method, string $contentType, string $appId, string $passphrase)
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

        $privateKey = openssl_pkey_get_private(config('laravel-myinfo-sg.private_key_path'), $passphrase);

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
     * @throws \Exception
     */
    public static function decryptJWE(string $personDataToken, string $privateKeyPath)
    {
        $jwk = JWKFactory::createFromKeyFile(
            $privateKeyPath,
            config('laravel-myinfo-sg.client_secret')
        );

        $serializerManager = new JWESerializerManager([
            new \Jose\Component\Encryption\Serializer\CompactSerializer(),
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
}
