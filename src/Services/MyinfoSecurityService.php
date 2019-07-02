<?php

namespace Ziming\LaravelMyinfoSg\Services;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Encryption\Compression\Deflate;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Compression\CompressionMethodManager;

/**
 * @internal
 */
final class MyinfoSecurityService
{
    /**
     * Verify JWS.
     *
     * @param string $accessToken
     * @return mixed|null
     * @throws \Exception
     */
    public static function verifyJWS(string $accessToken)
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
     * @param string $uri
     * @param array $params
     * @param string $method
     * @param string $contentType
     * @param string $authType
     * @param string $appId
     * @param string $passphrase
     * @return string
     * @throws \Exception
     */
    public static function generateAuthorizationHeader(string $uri, array $params, string $method, string $contentType,
                                                       string $authType, string $appId,
                                                       string $passphrase)
    {
        if ($authType === 'L2') {
            return self::generateSHA256withRSAHeader($uri, $params, $method, $contentType, $appId, $passphrase);
        }

        return '';
    }

    /**
     * Generate SHA256 with RSA Header.
     *
     * @param string $uri
     * @param array $params
     * @param string $method
     * @param string $contentType
     * @param string $appId
     * @param string $passphrase
     * @return string
     * @throws \Exception
     */
    private static function generateSHA256withRSAHeader(string $uri, array $params, string $method, string $contentType, string $appId, string $passphrase)
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

        $baseString = "{$method}&{$uri}&{$baseParamsStr}";

        $privateKey = openssl_get_privatekey(config('laravel-myinfo-sg.private_key_path'), $passphrase);

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
     * @param string $personDataToken
     * @param string $privateKeyPath
     * @return string
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
