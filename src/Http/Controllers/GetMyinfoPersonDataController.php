<?php

namespace Ziming\LaravelMyinfoSg\Http\Controllers;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidStateException;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;

class GetMyinfoPersonDataController extends Controller
{
    /**
     * Fetch MyInfo Person Data after authorization code is given back.
     *
     * @throws GuzzleException
     */
    public function __invoke(Request $request, LaravelMyinfoSg $laravelMyinfoSg, ResponseFactory $responseFactory): JsonResponse
    {
        $authCode = $request->input('code');
        $codeVerifier = $request->session()->pull('code_verifier');

        $privateSigningKey = file_get_contents(
            config('laravel-myinfo-sg.client_assertion_private_signing_key_path')
        );

        $privateEncryptionKeys = [];

        $files = File::allFiles(
            config('laravel-myinfo-sg.private_encryption_keys_folder_path')
        );

        foreach ($files as $file) {
            $privateEncryptionKeys[] = $file->getContents();
        }

        $personData = $laravelMyinfoSg->getMyinfoPersonData(
            $authCode,
            $codeVerifier,
            $privateSigningKey,
            $privateEncryptionKeys,
        );

        $this->preResponseHook($request, $personData);

        return $responseFactory->json($personData);
    }

    protected function preResponseHook(Request $request, array $personData): void
    {
        // Extend this class, override this method.
        // And do your logging and whatever stuffs here if needed.
        // person information is in the 'data' key of $personData array.
    }
}
