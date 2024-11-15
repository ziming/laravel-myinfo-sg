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
enum PassTypeEnum: string
{
    use Names, Values, Options;

    case WORK_PERMIT = 'Work Permit';
    case S_PASS = 'S Pass';
    case EMPLOYMENT_PASS = 'Employment Pass';
}
