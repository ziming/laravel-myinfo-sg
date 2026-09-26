<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Data\MyinfoV5;

final readonly class ValidatedAuthorizationCallback
{
    public function __construct(
        public string $code,
        public AuthorizationTransaction $transaction,
    ) {
    }
}
