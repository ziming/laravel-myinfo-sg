<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Laravel\Set\LaravelSetList;
use Rector\Nette\Set\NetteSetList;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\DowngradeLevelSetList;
use Rector\Set\ValueObject\LevelSetList;
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
        SetList::TYPE_DECLARATION_STRICT,
        //NetteSetList::NETTE_UTILS_CODE_QUALITY,
        //PHPUnitSetList::PHPUNIT_CODE_QUALITY,
//         SetList::CODING_STYLE,
    ]);
};
