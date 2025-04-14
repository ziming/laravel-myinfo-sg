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
        $myinfoConnector = new MyinfoConnector;

        $codeVerifier = Str::random(128);
        $encoded = base64_encode(hash('sha256', $codeVerifier, true));

        $codeChallenge = strtr(rtrim($encoded, '='), '+/', '-_');

        $state = Str::random(40);

        session()->put([
            config('laravel-myinfo-sg-v5.state_session_name') => $state,
            config('laravel-myinfo-sg-v5.code_verifier_session_name') => $codeVerifier,
        ]);

        $authorizationUrl = $myinfoConnector->getAuthorizationUrl(
            state: $state,
            additionalQueryParameters: [
                'nonce' => (string) Str::uuid(),
                'code_challenge_method' => 'S256',
                'code_challenge' => $codeChallenge,
            ]
        );

        if (config('laravel-myinfo-sg-v5.debug_mode')) {
            Log::debug('-- Authorise Call --');
            Log::debug('Server Call Time: '.Carbon::now()->toDayDateTimeString());
            Log::debug('Web Request URL: '.$authorizationUrl);
        }

        return redirect($authorizationUrl);
    }
}
