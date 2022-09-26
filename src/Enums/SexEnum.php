<?php

namespace Ziming\LaravelMyinfoSg\Enums;

use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;

/**
 * For my personal use, don't use it
 * @internal
 */
enum SexEnum: string
{
    use Names, Values, Options;

    case FEMALE = 'FEMALE';
    case MALE = 'MALE';
    case UNKNOWN = 'UNKNOWN';

    public static function getOptions(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
