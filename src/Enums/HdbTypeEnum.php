<?php

namespace Ziming\LaravelMyinfoSg\Enums;

use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;

/**
 * For my personal use, don't use it
 * @internal
 */
enum HdbTypeEnum: string
{
    use Names, Values, Options;

    // HDB
    case ONE_ROOM_FLAT = '1-ROOM FLAT (HDB)';
    case TWO_ROOM_FLAT = '2-ROOM FLAT (HDB)';
    case THREE_ROOM_FLAT = '3-ROOM FLAT (HDB)';
    case FOUR_ROOM_FLAT = '4-ROOM FLAT (HDB)';
    case FIVE_ROOM_FLAT = '5-ROOM FLAT (HDB)';
    case EXECUTIVE_FLAT = 'EXECUTIVE FLAT (HDB)';
    case STUDIO_APARTMENT = 'STUDIO APARTMENT (HDB)';

    // in really rare cases some people myinfo have this.
    case HDB = 'HDB';

    public static function getOptions(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
