<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Responses;

use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests\GetSingpassJwksRequest;
use Illuminate\Support\Arr;
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\AudienceChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\IssuedAtChecker;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA128KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA192KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA256KW;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\JWELoader;
use Jose\Component\Encryption\JWETokenSupport;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSLoader;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Saloon\Http\Response;
use Symfony\Component\Clock\Clock;

class GetUserResponse extends Response
{
    /**
     * @throws \JsonException
     */
    public function json(string|int|null $key = null, mixed $default = null): ?array
    {
        // 5 parts jwe token
        $jweToken = $this->body();

        $algorithmManager = new AlgorithmManager([
            new A256GCM,
            new ECDHESA128KW,
            new ECDHESA192KW,
            new ECDHESA256KW,
        ]);

        $jweSerializerManager = new JWESerializerManager([
            new CompactSerializer,
        ]);

        $jwe = $jweSerializerManager->unserialize($jweToken);

        $jweDecrypter = new JWEDecrypter($algorithmManager);

        $kid = $jwe->getSharedProtectedHeaderParameter('kid');

        // must it be private jwks, or can it be public?
        $jwkSet = JWKFactory::createFromJsonObject(
            config('laravel-myinfo-sg-v5.private_jwks')
        );

        $jwk = $jwkSet->get($kid);

        $headerCheckerManager = new HeaderCheckerManager([
            new AlgorithmChecker([
                'ECDH-ES+A256KW',
                'ECDH-ES+A192KW',
                'ECDH-ES+A128KW',
            ]),
        ], [
            new JWETokenSupport,
        ]);

        $jweLoader = new JWELoader($jweSerializerManager, $jweDecrypter, $headerCheckerManager);

        $jwe = $jweLoader->loadAndDecryptWithKey($jweToken, $jwk, $recipient);

        // this is a jws in jwe. this jws contains the myinfo data
        // it is 5 parts
        $jwsToken = $jwe->getPayload();

        $myinfoResponsePayload = $this->decodeMyinfoJwsPayload($jwsToken);

        return Arr::get($myinfoResponsePayload, $key, $default);
    }

    /**
     * @throws \JsonException
     */
    private function decodeMyinfoJwsPayload(string $jwsToken): array
    {
        $getSingpassJwksRequest = new GetSingpassJwksRequest;

        $singpassJwksResponse = $getSingpassJwksRequest->send();

        $singpassPublicJwks = JWKSet::createFromJson(
            $singpassJwksResponse->body()
        );

        $algorithmManager = new AlgorithmManager([
            new ES256,
        ]);

        $jwsVerifier = new JWSVerifier($algorithmManager);

        $jwsSerializerManager = new JWSSerializerManager([
            new \Jose\Component\Signature\Serializer\CompactSerializer,
        ]);

        $headerCheckerManager = new HeaderCheckerManager([
            new AlgorithmChecker(['ES256']),
        ], [
            new JWSTokenSupport,
        ]);

        $kid = $jwsSerializerManager
            ->unserialize($jwsToken)
            ->getSignature(0)
            ->getProtectedHeaderParameter('kid');

        $currentSingpassJwk = $singpassPublicJwks->get($kid);

        $jwsLoader = new JWSLoader(
            $jwsSerializerManager,
            $jwsVerifier,
            $headerCheckerManager,
        );

        $jws = $jwsLoader->loadAndVerifyWithKey($jwsToken, $currentSingpassJwk, $signature);

        $myinfoPersonPayload = json_decode(
            $jws->getPayload(),
            true
        );

        $clock = new Clock;

        $claimCheckerManager = new ClaimCheckerManager(
            [
                new AudienceChecker(
                    config('laravel-myinfo-sg-v5.client_id')
                ),
                new IssuerChecker([
                    config('laravel-myinfo-sg-v5.issuer_url')
                ]),
                new IssuedAtChecker($clock, 2),
            ]
        );

        $claimCheckerManager->check($myinfoPersonPayload);

        return $myinfoPersonPayload;
    }
}
