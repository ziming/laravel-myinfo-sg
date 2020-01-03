<?php

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;

class CallAuthoriseApiController extends Controller
{
    /**
     * Redirects to Singpass for user to give permission to fetch MyInfo Data.
     *
     * @param LaravelMyinfoSg $laravelMyinfoSg
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Exception
     */
    public function __invoke(Request $request, LaravelMyinfoSg $laravelMyinfoSg)
    {
        $state = Str::random(40);
        $authoriseApiUrl = $laravelMyinfoSg->generateAuthoriseApiUrl($state);
        $request->session()->put('state', $state);
        return redirect($authoriseApiUrl);
    }
}
