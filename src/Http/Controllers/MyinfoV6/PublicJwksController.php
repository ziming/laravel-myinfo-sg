<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use RuntimeException;

class PublicJwksController extends Controller
{
    /**
     * @throws \JsonException
     */
    public function __invoke(ResponseFactory $responseFactory): JsonResponse
    {
        return $responseFactory->json(
            $this->resolvePublicJwksPayload()
        );
    }

    /**
     * @return array<string, mixed>
     * @throws \JsonException
     */
    private function resolvePublicJwksPayload(): array
    {
        $publicJwks = config('laravel-myinfo-sg-v6.public_jwks');

        if (is_string($publicJwks) && $publicJwks !== '') {
            $decoded = json_decode($publicJwks, true, 512, JSON_THROW_ON_ERROR);
        } elseif (is_array($publicJwks)) {
            $decoded = $publicJwks;
        } else {
            throw new RuntimeException('The laravel-myinfo-sg-v6.public_jwks config must be a JSON string or array.');
        }

        if (array_is_list($decoded)) {
            return ['keys' => $decoded];
        }

        return $decoded;
    }
}
