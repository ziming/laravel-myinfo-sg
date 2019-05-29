<?php

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;

class CallAuthoriseApiController
{
    /**
     * Redirects to Singpass for user to give permission to fetch MyInfo Data
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Exception
     */
    public function __invoke()
    {
        return redirect((new LaravelMyinfoSg)->generateAuthoriseApiUri());
    }
}
