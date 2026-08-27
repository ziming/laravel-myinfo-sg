<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV5;

use Ziming\LaravelMyinfoSg\Tests\TestCase;
use Ziming\LaravelMyinfoSg\Utils\MyinfoV5RawValueFetcher;

class MyinfoV5RawValueFetcherTest extends TestCase
{
    public function test_fetches_person_values_from_the_raw_v5_userinfo_shape(): void
    {
        $fetcher = MyinfoV5RawValueFetcher::make([
            'person_info' => [
                'uinfin' => ['value' => 'S9000001B'],
                'name' => ['value' => 'SOH HAO FENG'],
                'sex' => ['code' => 'M', 'desc' => 'MALE'],
                'mobileno' => [
                    'prefix' => ['value' => '+'],
                    'areacode' => ['value' => '65'],
                    'nbr' => ['value' => '81234567'],
                ],
                'regadd' => [
                    'block' => ['value' => '123'],
                    'street' => ['value' => 'TEST STREET'],
                    'postal' => ['value' => '123456'],
                ],
                'vehicles' => [
                    [
                        'make' => ['value' => 'HONDA'],
                        'model' => ['value' => 'CIVIC'],
                    ],
                ],
            ],
            'iss' => 'https://id.singpass.gov.sg/fapi',
            'sub' => 'd45d8f21-6178-4713-b962-8635ed2a945a',
            'aud' => 'T5sM5a53Yaw3URyDEv2y9129CbElCN2F',
            'iat' => 1746678089,
        ]);

        $this->assertTrue($fetcher->isNotEmpty());
        $this->assertSame('S9000001B', $fetcher->uinfin());
        $this->assertSame('SOH HAO FENG', $fetcher->name());
        $this->assertSame('M', $fetcher->sex('code'));
        $this->assertSame('+6581234567', $fetcher->mobilePhoneFull());
        $this->assertSame('TEST STREET', $fetcher->regAddStreet());
        $this->assertSame('HONDA', $fetcher->vehiclesRowMake(0));
        $this->assertSame('CIVIC', $fetcher->vehiclesRowModel(0));
    }

    public function test_does_not_treat_top_level_v5_claims_as_person_information(): void
    {
        $fetcher = MyinfoV5RawValueFetcher::make([
            'name' => ['value' => 'TOP LEVEL NAME'],
            'sub' => 'verified-subject',
        ]);

        $this->assertFalse($fetcher->isNotEmpty());
        $this->assertNull($fetcher->name());
        $this->assertNull($fetcher->uinfin());
    }

    public function test_treats_a_non_object_person_info_value_as_empty(): void
    {
        $fetcher = MyinfoV5RawValueFetcher::make([
            'person_info' => 'invalid',
        ]);

        $this->assertFalse($fetcher->isNotEmpty());
        $this->assertNull($fetcher->name());
    }
}
