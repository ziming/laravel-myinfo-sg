<?php

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;

class CallAuthoriseApiController
{
    /**
     * Redirects to Singpass for user to give permission to fetch MyInfo Data
     *
     * @param LaravelMyinfoSg $laravelMyinfoSg
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Exception
     */
    public function __invoke(LaravelMyinfoSg $laravelMyinfoSg)
    {
        return redirect($laravelMyinfoSg->generateAuthoriseApiUri());
    }
}
