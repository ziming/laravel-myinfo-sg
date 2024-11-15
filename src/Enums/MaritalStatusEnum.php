<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Enums;

use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;

/**
 * For my personal use, don't use it
 * @internal
 */
enum MaritalStatusEnum: string
{
    use Names, Values, Options;

    case SINGLE = 'SINGLE';
    case MARRIED = 'MARRIED';
    case WIDOWED = 'WIDOWED';
    case DIVORCED = 'DIVORCED';

    public static function getOptions(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
