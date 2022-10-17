<?php

namespace Ziming\LaravelMyinfoSg\Enums;

use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;

/**
 * For my personal use, don't use it
 * @internal
 */
enum HousingTypeEnum: string
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

    // Private
    case DETACHED_HOUSE = 'DETACHED HOUSE';
    case SEMI_DETACHED_HOUSE = 'SEMI-DETACHED HOUSE';
    case TERRACE_HOUSE = 'TERRACE HOUSE';
    case CONDOMINIUM = 'CONDOMINIUM';
    case EXECUTIVE_CONDOMINIUM = 'EXECUTIVE CONDOMINIUM';
    case APARTMENT = 'APARTMENT';

    public static function getOptions(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
