<?php

namespace Ziming\LaravelMyinfoSg\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidStateException extends HttpException
{
    /**
     * InvalidStateException constructor.
     * @param int $statusCode
     * @param string $message
     * @param \Exception|null $previous
     * @param array $headers
     * @param int $code
     */
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
