<?php

namespace Ziming\LaravelMyinfoSg\Enums;

/**
 * For my personal use, don't use it
 * @internal
 */
enum SexEnum: string
{
    case FEMALE = 'FEMALE';
    case MALE = 'MALE';
    case UNKNOWN = 'UNKNOWN';

    public static function getOptions(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
