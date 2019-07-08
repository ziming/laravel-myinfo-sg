<?php

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Illuminate\Http\Request;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;

class GetMyinfoPersonDataController
{
    /**
     * Fetch MyInfo Person Data after authorization code is given back.
     *
     * @param Request $request
     * @param LaravelMyinfoSg $laravelMyinfoSg
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function __invoke(Request $request, LaravelMyinfoSg $laravelMyinfoSg)
    {
        $personData = $laravelMyinfoSg->getMyinfoPersonData($request);

        $this->preResponseHook($personData);

        return response()->json($personData);
    }

    /**
     * @param array $personData
     */
    protected function preResponseHook(array $personData)
    {
        // Extend this class, override this method.
        // And do your logging and whatever stuffs here if needed.
        // person information is in the 'data' key of $personData array.
    }
}
