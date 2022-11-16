<?php

namespace Ziming\LaravelMyinfoSg\Services;

use Illuminate\Support\Facades\Http;

/**
 * Most likely future use of this class is in seeders
 *
 * For my personal use, don't use it
 * @internal
 */
class MyinfoSandboxApi
{
    /**
     * @see https://api.singpass.gov.sg/library/myinfo/developers/tutorial1
     */
    public static array $sampleUinfins = [
        'S9812381D',
        'S9812382B',
        'S9812385G',
        'S9812387C',
        'S9912363Z',
        'S9912366D',
        'S9912369I',
        'S9912370B',
        'S9912372I',
        'S9912374E',
        'S6005053H',
        'S6005055D',
        'S9812379B',

        'F1612347K',

        'G1612348Q',
        'G1612349N',
        'G1612350T',
        'G1612352N',
        'G1612353L',
    ];

    public static function fetchSandboxProfile(string $uinfin): array
    {
        // In future, might want throw exception or return empty array if http call fail.
        return Http::get('https://sandbox.api.myinfo.gov.sg/com/v3/person-sample/' . $uinfin)
            ->json();
    }

    public static function fetchRandomSandboxProfile(): array
    {
        return self::fetchSandboxProfile(
            self::$sampleUinfins[array_rand(self::$sampleUinfins)]
        );
    }


}
