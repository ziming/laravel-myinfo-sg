<?php

namespace Ziming\LaravelMyinfoSg\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidStateException extends HttpException
{
    public function __construct(int $statusCode = 404, string $message = 'Invalid State', \Exception $previous = null, array $headers = [], ?int $code = 0)
    {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request
     * @return \Illuminate\Http\JsonResponse
     */
    public function render()
    {
        return response()->json([
            'message' => $this->message,
        ], $this->getStatusCode());
    }
}
