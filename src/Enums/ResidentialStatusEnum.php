<?php

namespace Ziming\LaravelMyinfoSg\Enums;

use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;

/**
 * For my personal use, don't use it
 * @internal
 */
enum ResidentialStatusEnum: string
{
    use Names, Values, Options;

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
