<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use InvalidArgumentException;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use RuntimeException;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\JwkSetValidator;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\SingpassAlgorithmProfile;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class JwkSetValidatorTest extends TestCase
{
    private JwkSetValidator $validator;

    public function setUp(): void
    {
        parent::setUp();

        $this->validator = new JwkSetValidator;
    }

    public function test_algorithm_policy_exposes_only_the_context_specific_profiles(): void
    {
        $signingAlgorithms = [
            'ES256' => 'P-256',
            'ES384' => 'P-384',
            'ES512' => 'P-521',
        ];

        $this->assertSame($signingAlgorithms, SingpassAlgorithmProfile::clientAssertionSigningAlgorithms());
        $this->assertSame($signingAlgorithms, SingpassAlgorithmProfile::dpopSigningAlgorithms());
        $this->assertSame(
            ['ECDH-ES+A128KW', 'ECDH-ES+A192KW', 'ECDH-ES+A256KW'],
            SingpassAlgorithmProfile::jweKeyWrappingAlgorithms()
        );
        $this->assertSame(['P-256', 'P-384', 'P-521'], SingpassAlgorithmProfile::encryptionKeyCurves());
        $this->assertSame(['ES256'], SingpassAlgorithmProfile::idTokenSigningAlgorithms());
        $this->assertSame(['A256CBC-HS512'], SingpassAlgorithmProfile::idTokenContentEncryptionAlgorithms());
        $this->assertSame(['ES256'], SingpassAlgorithmProfile::userInfoSigningAlgorithms());
        $this->assertSame(['A256GCM'], SingpassAlgorithmProfile::userInfoContentEncryptionAlgorithms());

        foreach ($signingAlgorithms as $algorithm => $curve) {
            $this->assertSame($curve, SingpassAlgorithmProfile::clientAssertionCurve($algorithm));
            $this->assertSame($curve, SingpassAlgorithmProfile::dpopCurve($algorithm));
        }
    }

    public function test_algorithm_policy_rejects_unknown_context_values(): void
    {
        try {
            SingpassAlgorithmProfile::clientAssertionCurve('RS256');
            $this->fail('Expected an unknown client assertion algorithm to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('client assertion', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DPoP');

        SingpassAlgorithmProfile::dpopCurve('RS256');
    }

    public function test_public_jwks_accepts_every_supported_signing_profile(): void
    {
        foreach (SingpassAlgorithmProfile::clientAssertionSigningAlgorithms() as $algorithm => $curve) {
            $payload = [
                'keys' => [
                    $this->publicKey($curve, $algorithm, 'sig', "sig-{$algorithm}"),
                    $this->publicKey('P-256', 'ECDH-ES+A128KW', 'enc', "enc-{$algorithm}"),
                ],
            ];

            $this->assertSame($payload, $this->validator->validatePublicJwks($payload));
        }
    }

    public function test_public_jwks_accepts_every_supported_encryption_algorithm_and_curve(): void
    {
        foreach (SingpassAlgorithmProfile::jweKeyWrappingAlgorithms() as $algorithm) {
            foreach (SingpassAlgorithmProfile::encryptionKeyCurves() as $curve) {
                $payload = [
                    'keys' => [
                        $this->publicKey('P-256', 'ES256', 'sig', "sig-{$algorithm}-{$curve}"),
                        $this->publicKey($curve, $algorithm, 'enc', "enc-{$algorithm}-{$curve}"),
                    ],
                ];

                $this->assertSame($payload, $this->validator->validatePublicJwks($payload));
            }
        }
    }

    public function test_public_signing_algorithm_is_optional_and_compatible_key_ops_are_accepted(): void
    {
        $signingKey = $this->publicKey('P-256', 'ES256', 'sig', 'sig-without-alg');
        unset($signingKey['alg']);
        $signingKey['key_ops'] = ['verify'];
        $encryptionKey = $this->publicKey('P-256', 'ECDH-ES+A128KW', 'enc', 'enc-with-ops');
        $encryptionKey['key_ops'] = ['encrypt', 'deriveKey'];

        $payload = ['keys' => [$signingKey, $encryptionKey]];

        $this->assertSame($payload, $this->validator->validatePublicJwks($payload));
    }

    public function test_private_jwks_returns_a_validated_jwk_set(): void
    {
        $payload = $this->privateJwks();

        $jwkSet = $this->validator->validatePrivateJwks(
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $this->assertInstanceOf(JWKSet::class, $jwkSet);
        $this->assertCount(2, $jwkSet);
        $this->assertTrue($jwkSet->has('sig-1'));
        $this->assertTrue($jwkSet->has('enc-1'));
    }

    public function test_it_rejects_invalid_top_level_key_lists(): void
    {
        foreach ([null, '', '{}', '{invalid', [], ['keys' => []], [$this->publicKey('P-256', 'ES256', 'sig', 'sig-1')]] as $payload) {
            try {
                $this->validator->validatePublicJwks($payload);
                $this->fail('Expected an invalid top-level keys list to be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('[keys]', $exception->getMessage());
            }
        }
    }

    public function test_public_jwks_rejects_duplicate_kid_and_invalid_key_fields_without_disclosing_material(): void
    {
        $secret = 'never-include-this-private-coordinate';
        $valid = $this->publicJwks();
        $cases = [];

        $duplicateKid = $valid;
        $duplicateKid['keys'][1]['kid'] = 'sig-1';
        $cases[] = [$duplicateKid, 'kid', 'sig-1'];

        $nonEc = $valid;
        $nonEc['keys'][0]['kty'] = 'RSA';
        $cases[] = [$nonEc, 'kty', 'sig-1'];

        $invalidCurve = $valid;
        $invalidCurve['keys'][1]['crv'] = 'secp256k1';
        $cases[] = [$invalidCurve, 'crv', 'enc-1'];

        $invalidUse = $valid;
        $invalidUse['keys'][0]['use'] = 'other';
        $cases[] = [$invalidUse, 'use', 'sig-1'];

        $privateMaterial = $valid;
        $privateMaterial['keys'][0]['d'] = $secret;
        $cases[] = [$privateMaterial, 'd', 'sig-1'];

        $invalidEncryptionAlgorithm = $valid;
        $invalidEncryptionAlgorithm['keys'][1]['alg'] = 'RSA-OAEP';
        $cases[] = [$invalidEncryptionAlgorithm, 'alg', 'enc-1'];

        $missingCoordinate = $valid;
        unset($missingCoordinate['keys'][0]['x']);
        $cases[] = [$missingCoordinate, 'x', 'sig-1'];

        $incompatibleKeyOperation = $valid;
        $incompatibleKeyOperation['keys'][0]['key_ops'] = ['decrypt'];
        $cases[] = [$incompatibleKeyOperation, 'key_ops', 'sig-1'];

        foreach ($cases as [$payload, $field, $kid]) {
            try {
                $this->validator->validatePublicJwks($payload);
                $this->fail("Expected invalid field {$field} to be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("[{$field}]", $exception->getMessage());
                $this->assertStringContainsString("[{$kid}]", $exception->getMessage());
                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
        }
    }

    public function test_public_jwks_requires_both_key_roles(): void
    {
        foreach ([
            ['sig', $this->publicKey('P-256', 'ES256', 'sig', 'sig-1')],
            ['enc', $this->publicKey('P-256', 'ECDH-ES+A128KW', 'enc', 'enc-1')],
        ] as [$presentRole, $key]) {
            try {
                $this->validator->validatePublicJwks(['keys' => [$key]]);
                $this->fail("Expected a public set containing only {$presentRole} to be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('[use]', $exception->getMessage());
            }
        }
    }

    public function test_public_jwks_rejects_every_wrong_signing_algorithm_curve_mapping(): void
    {
        $wrongCurves = [
            'ES256' => 'P-384',
            'ES384' => 'P-521',
            'ES512' => 'P-256',
        ];

        foreach ($wrongCurves as $algorithm => $curve) {
            $payload = $this->publicJwks();
            $payload['keys'][0] = $this->publicKey($curve, $algorithm, 'sig', "sig-{$algorithm}");

            try {
                $this->validator->validatePublicJwks($payload);
                $this->fail("Expected {$algorithm} with {$curve} to be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('[crv]', $exception->getMessage());
                $this->assertStringContainsString("[sig-{$algorithm}]", $exception->getMessage());
            }
        }
    }

    public function test_private_jwks_requires_private_material_and_a_signing_algorithm(): void
    {
        $valid = $this->privateJwks();
        $cases = [];

        $missingPrivateMaterial = $valid;
        unset($missingPrivateMaterial['keys'][1]['d']);
        $cases[] = [$missingPrivateMaterial, 'd', 'enc-1'];

        $missingSigningAlgorithm = $valid;
        unset($missingSigningAlgorithm['keys'][0]['alg']);
        $cases[] = [$missingSigningAlgorithm, 'alg', 'sig-1'];

        $wrongSigningCurve = $valid;
        $wrongSigningCurve['keys'][0]['crv'] = 'P-384';
        $cases[] = [$wrongSigningCurve, 'crv', 'sig-1'];

        foreach ($cases as [$payload, $field, $kid]) {
            try {
                $this->validator->validatePrivateJwks($payload);
                $this->fail("Expected invalid private field {$field} to be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("[{$field}]", $exception->getMessage());
                $this->assertStringContainsString("[{$kid}]", $exception->getMessage());
            }
        }
    }

    public function test_pair_validation_accepts_matching_keys_and_private_encryption_rotation_overlap(): void
    {
        $privateJwks = $this->privateJwks();
        $publicJwks = $this->toPublicJwks($privateJwks);

        $this->validator->validatePair($publicJwks, $privateJwks);

        $privateJwks['keys'][] = $this->privateKey(
            'P-384',
            'ECDH-ES+A192KW',
            'enc',
            'enc-old'
        );

        $this->validator->validatePair($publicJwks, $privateJwks);
        $this->addToAssertionCount(1);
    }

    public function test_pair_validation_rejects_coordinate_mismatch_and_unmatched_signing_keys(): void
    {
        $privateJwks = $this->privateJwks();
        $publicJwks = $this->toPublicJwks($privateJwks);
        $publicJwks['keys'][0]['x'] = 'different-public-coordinate';

        try {
            $this->validator->validatePair($publicJwks, $privateJwks);
            $this->fail('Expected mismatched coordinates to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('[x]', $exception->getMessage());
            $this->assertStringContainsString('[sig-1]', $exception->getMessage());
            $this->assertStringNotContainsString('different-public-coordinate', $exception->getMessage());
        }

        $privateJwks = $this->privateJwks();
        $publicJwks = $this->toPublicJwks($privateJwks);
        $privateJwks['keys'][] = $this->privateKey('P-384', 'ES384', 'sig', 'sig-unmatched');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[sig-unmatched]');

        $this->validator->validatePair($publicJwks, $privateJwks);
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
     * @return array{keys: list<array<string, mixed>>}
     */
    private function privateJwks(): array
    {
        return [
            'keys' => [
                $this->privateKey('P-256', 'ES256', 'sig', 'sig-1'),
                $this->privateKey('P-256', 'ECDH-ES+A128KW', 'enc', 'enc-1'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function privateKey(string $curve, string $algorithm, string $use, string $kid): array
    {
        return JWKFactory::createECKey($curve, [
            'alg' => $algorithm,
            'use' => $use,
            'kid' => $kid,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicKey(string $curve, string $algorithm, string $use, string $kid): array
    {
        return (new JWK($this->privateKey($curve, $algorithm, $use, $kid)))->toPublic()->all();
    }

    /**
     * @param array{keys: list<array<string, mixed>>} $privateJwks
     * @return array{keys: list<array<string, mixed>>}
     */
    private function toPublicJwks(array $privateJwks): array
    {
        return [
            'keys' => array_map(
                static fn (array $key): array => (new JWK($key))->toPublic()->all(),
                $privateJwks['keys']
            ),
        ];
    }
}
