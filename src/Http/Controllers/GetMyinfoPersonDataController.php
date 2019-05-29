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
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function __invoke(Request $request)
    {
        $personData = (new LaravelMyinfoSg)->getMyinfoPersonData($request);
        return response()->json($personData);
    }

}