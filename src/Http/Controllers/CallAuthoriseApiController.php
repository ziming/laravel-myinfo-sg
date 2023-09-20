<?php

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;

class CallAuthoriseApiController extends Controller
{
    /**
     * Redirects to Singpass for user to give permission to fetch MyInfo Data.
     *
     * @throws Exception
     */
    public function __invoke(Request $request, LaravelMyinfoSg $laravelMyinfoSg, Redirector $redirector): Redirector|RedirectResponse
    {
        $codeVerifier = Str::random(128);
        $encoded = base64_encode(hash('sha256', $codeVerifier, true));
        $codeChallenge = strtr(rtrim($encoded, '='), '+/', '-_');

        $authoriseApiUrl = $laravelMyinfoSg->generateAuthoriseApiUrl($codeChallenge);
        $request->session()->put('code_verifier', $codeVerifier);

        $request->session()->put('state', $state = Str::random(40));

        if (config('laravel-myinfo-sg.debug_mode')) {
            Log::debug('-- Authorise Call --');
            Log::debug('Server Call Time: '.Carbon::now()->toDayDateTimeString());
            Log::debug('Web Request URL: '.$authoriseApiUrl);
        }

        return $redirector->to($authoriseApiUrl);
    }
}
