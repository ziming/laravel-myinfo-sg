<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV5;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Ziming\LaravelMyinfoSg\Services\MyinfoV5\JwkSetValidator;

class PublicJwksController extends Controller
{
    public function __construct(private readonly JwkSetValidator $jwkSetValidator) {}

    /**
     * @throws \RuntimeException
     */
    public function __invoke(ResponseFactory $responseFactory): JsonResponse
    {
        return $responseFactory->json(
            $this->resolvePublicJwksPayload()
        );
    }

    /**
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    private function resolvePublicJwksPayload(): array
    {
        return $this->jwkSetValidator->validatePublicJwks(
            config('laravel-myinfo-sg-v5.public_jwks')
        );
    }
}
