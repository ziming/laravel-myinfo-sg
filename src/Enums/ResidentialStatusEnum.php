<?php

namespace Ziming\LaravelMyinfoSg\Enums;

/**
 * For my personal use, don't use it
 * @internal
 */
enum ResidentialStatusEnum: string
{
    case ALIEN = 'ALIEN';
    case CITIZEN = 'CITIZEN';
    case PR = 'PR';
    case UNKNOWN = 'UNKNOWN';
    case NOT_APPLICABLE = 'NOT APPLICABLE';

    public static function getOptions(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
