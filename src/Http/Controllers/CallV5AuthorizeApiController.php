<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\MyinfoConnector;

class CallV5AuthorizeApiController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'redirect_uri' => ['nullable', 'string', 'url'],
        ]);

        $redirectUri = $request->input('redirect_uri');

        $myinfoConnector = new MyinfoConnector;

        $authorizationUrl = $myinfoConnector->generateAuthorizationUrl($redirectUri);

        if (config('laravel-myinfo-sg-v5.debug_mode')) {
            Log::debug('-- Authorise Call --');
            Log::debug('Server Call Time: '.Carbon::now()->toDayDateTimeString());
            Log::debug('Web Request URL: '.$authorizationUrl);
        }

        return redirect($authorizationUrl);
    }
}
