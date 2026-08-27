<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6;

use RuntimeException;

/** @internal */
final class SigningKeyRefreshRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider signing keys must be refreshed.');
    }
}
