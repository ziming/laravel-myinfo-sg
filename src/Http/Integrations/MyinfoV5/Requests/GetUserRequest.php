<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests;

use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Responses\GetUserResponse;

class GetUserRequest extends \Saloon\Http\OAuth2\GetUserRequest
{
    protected ?string $response = GetUserResponse::class;
}
