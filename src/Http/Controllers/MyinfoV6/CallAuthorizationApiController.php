<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;

class CallAuthorizationApiController extends Controller
{
    public function __invoke(
        MyinfoConnector $myinfoConnector,
        Redirector $redirector
    ): Redirector|RedirectResponse {
        return $redirector->to(
            $myinfoConnector->generateAuthorizationUrl()
        );
    }
}
