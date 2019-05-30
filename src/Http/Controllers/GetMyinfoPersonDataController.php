<?php

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;
use Illuminate\Http\Request;

class GetMyinfoPersonDataController
{
    /**
     * Fetch MyInfo Person Data after authorization code is given back
     *
     * @param Request $request
     * @param LaravelMyinfoSg $laravelMyinfoSg
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function __invoke(Request $request, LaravelMyinfoSg $laravelMyinfoSg)
    {
        $personData = $laravelMyinfoSg->getMyinfoPersonData($request);
        return response()->json($personData);
    }

}