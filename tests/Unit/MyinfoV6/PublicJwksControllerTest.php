<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Illuminate\Contracts\Routing\ResponseFactory;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use RuntimeException;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\PublicJwksController;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class PublicJwksControllerTest extends TestCase
{
    public function test_it_returns_a_valid_json_public_jwks(): void
    {
        $payload = $this->publicJwks();
        config()->set(
            'laravel-myinfo-sg-v6.public_jwks',
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $this->assertSame($payload, $this->invokeController());
    }

    public function test_it_returns_a_valid_array_public_jwks_and_discards_top_level_metadata(): void
    {
        $payload = $this->publicJwks();
        config()->set('laravel-myinfo-sg-v6.public_jwks', [
            ...$payload,
            'untrusted_metadata' => 'not returned',
        ]);

        $this->assertSame($payload, $this->invokeController());
    }

    public function test_it_accepts_signing_key_rotation_with_multiple_public_signing_keys(): void
    {
        $payload = $this->publicJwks();
        array_splice($payload['keys'], 1, 0, [
            $this->publicKey('P-384', 'ES384', 'sig', 'sig-2'),
        ]);
        config()->set('laravel-myinfo-sg-v6.public_jwks', $payload);

        $response = $this->invokeController();

        $this->assertCount(3, $response['keys']);
        $this->assertSame(['sig-1', 'sig-2', 'enc-1'], array_column($response['keys'], 'kid'));
    }

    public function test_it_fails_closed_for_invalid_public_key_configuration(): void
    {
        $secret = 'never-return-this-private-value';
        $valid = $this->publicJwks();
        $cases = [];

        $privateKey = $valid;
        $privateKey['keys'][0]['d'] = $secret;
        $cases[] = [$privateKey, 'd', 'sig-1'];

        $duplicateKid = $valid;
        $duplicateKid['keys'][1]['kid'] = 'sig-1';
        $cases[] = [$duplicateKid, 'kid', 'sig-1'];

        $missingEncryptionKey = $valid;
        array_pop($missingEncryptionKey['keys']);
        $cases[] = [$missingEncryptionKey, 'use', null];

        $nonEc = $valid;
        $nonEc['keys'][0]['kty'] = 'RSA';
        $cases[] = [$nonEc, 'kty', 'sig-1'];

        $invalidCurve = $valid;
        $invalidCurve['keys'][0]['crv'] = 'secp256k1';
        $cases[] = [$invalidCurve, 'crv', 'sig-1'];

        $invalidEncryptionAlgorithm = $valid;
        $invalidEncryptionAlgorithm['keys'][1]['alg'] = 'RSA-OAEP';
        $cases[] = [$invalidEncryptionAlgorithm, 'alg', 'enc-1'];

        foreach ($cases as [$payload, $field, $kid]) {
            config()->set('laravel-myinfo-sg-v6.public_jwks', $payload);

            try {
                $this->invokeController();
                $this->fail("Expected invalid field {$field} to fail closed.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("[{$field}]", $exception->getMessage());

                if ($kid !== null) {
                    $this->assertStringContainsString("[{$kid}]", $exception->getMessage());
                }

                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeController(): array
    {
        $response = $this->app->make(PublicJwksController::class)(
            $this->app->make(ResponseFactory::class)
        );

        return $response->getData(true);
    }

    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    private function publicJwks(): array
    {
        return [
            'keys' => [
                $this->publicKey('P-256', 'ES256', 'sig', 'sig-1'),
                $this->publicKey('P-256', 'ECDH-ES+A128KW', 'enc', 'enc-1'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicKey(string $curve, string $algorithm, string $use, string $kid): array
    {
        return (new JWK(JWKFactory::createECKey($curve, [
            'alg' => $algorithm,
            'use' => $use,
            'kid' => $kid,
        ])->all()))->toPublic()->all();
    }
}
