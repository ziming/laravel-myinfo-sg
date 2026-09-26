<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5;

use RuntimeException;

final class AuthorizationResponseException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('The authorization provider returned an error.');
    }
}
