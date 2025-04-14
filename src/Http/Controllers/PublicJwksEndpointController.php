<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicJwksEndpointController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $ourJwks = config('laravel-myinfo-sg-v5.public_jwks');

        return response()->json(
            json_decode($ourJwks, true)
        );
    }
}
