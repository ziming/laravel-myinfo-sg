<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV5;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Ziming\LaravelMyinfoSg\Services\MyinfoV6\JwkSetValidator;

class PublicJwksController extends Controller
{
    // JwkSetValidator lives under the V6 namespace but reads no V6 config: it validates
    // whatever JWKS it is handed against the Singpass JWKS requirements, which V5 shares.
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
