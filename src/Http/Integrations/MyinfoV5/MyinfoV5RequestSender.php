<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5;

use Closure;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5\MyinfoV5TransportException;

final class MyinfoV5RequestSender
{
    /** @var list<int> */
    private const array RETRYABLE_STATUSES = [429, 502, 503, 504];

    public function send(
        MyinfoV5Request $request,
        string $endpoint,
        bool $restartAuthorization,
        ?Connector $connector = null,
    ): Response {
        try {
            $response = $connector instanceof Connector
                ? $connector->send($request)
                : $request->send();
        } catch (FatalRequestException|RequestException $exception) {
            throw new MyinfoV5TransportException(
                $endpoint,
                $restartAuthorization,
                $exception,
            );
        }

        if (in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
            throw new MyinfoV5TransportException($endpoint, $restartAuthorization);
        }

        return $response;
    }

    /**
     * Build a new request for each safe-read attempt when security material in
     * headers must never be reused.
     *
     * @param Closure(): MyinfoV5Request $requestFactory
     * @internal
     */
    public function sendWithRequestFactory(
        Closure $requestFactory,
        string $endpoint,
        bool $restartAuthorization,
        ?Connector $connector = null,
    ): Response {
        $firstRequest = $requestFactory();
        $maxAttempts = $firstRequest->safeReadMaxAttempts();
        $retryDelay = $firstRequest->safeReadRetryDelayMilliseconds();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $request = $attempt === 1 ? $firstRequest : $requestFactory();
            $request->tries = 1;

            if ($attempt > 1 && $retryDelay > 0) {
                usleep($retryDelay * 1000);
            }

            try {
                $response = $connector instanceof Connector
                    ? $connector->send($request)
                    : $request->send();
            } catch (FatalRequestException|RequestException $exception) {
                $retryable = $exception instanceof FatalRequestException
                    || in_array($exception->getResponse()->status(), self::RETRYABLE_STATUSES, true);

                if ($retryable && $attempt < $maxAttempts) {
                    continue;
                }

                throw new MyinfoV5TransportException(
                    $endpoint,
                    $restartAuthorization,
                    $exception,
                );
            }

            if (in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
                if ($attempt < $maxAttempts) {
                    continue;
                }

                throw new MyinfoV5TransportException($endpoint, $restartAuthorization);
            }

            return $response;
        }

        throw new \LogicException('The MyInfo V5 request factory did not send a request.');
    }
}
