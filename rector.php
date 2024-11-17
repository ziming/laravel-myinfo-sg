<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
    ]);

    // register a single rule
    // $rectorConfig->rule(InlineConstructorDefaultToPropertyRector::class);

    // $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');

    // https://github.com/rectorphp/rector/blob/main/docs/auto_import_names.md
    $rectorConfig->importNames();

    // define sets of rules
    $rectorConfig->sets([
        // DowngradeLevelSetList::DOWN_TO_PHP_81,
        // LevelSetList::UP_TO_PHP_81,
        //        LaravelSetList::LARAVEL_90,
        // SetList::CODE_QUALITY,
        //        SetList::DEAD_CODE,
        //         SetList::PRIVATIZATION,
        //        SetList::NAMING,
        SetList::TYPE_DECLARATION,
        // SetList::EARLY_RETURN,
        //PHPUnitSetList::PHPUNIT_CODE_QUALITY,
//         SetList::CODING_STYLE,
    ]);
};
