<?php

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidStateException;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;

class JwksUriController extends Controller
{
    public function __invoke(Request $request, LaravelMyinfoSg $laravelMyinfoSg, ResponseFactory $responseFactory): array|JsonResponse
    {
        return [
            'keys' => [
                [
                    'kty' => 'EC',
                    'use' => 'sig',
                    'crv' => 'P-521',
                    'alg' => 'ES512',

                    // the others
                ],
                [
                    'kty' => 'EC',
                    'use' => 'enc',
                    'crv' => 'P-521',
                    'alg' => 'ECDH-ES+A256KW',

                    // the others
                ]
            ]
        ];
    }
}
