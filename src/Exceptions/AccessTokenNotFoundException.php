<?php

namespace Ziming\LaravelMyinfoSg\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AccessTokenNotFoundException extends HttpException
{
    public function __construct(int $statusCode = 404, string $message = 'Access Token Not Found', \Exception $previous = null, array $headers = [], ?int $code = 0)
    {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => $this->message,
        ], $this->getStatusCode());
    }
}
