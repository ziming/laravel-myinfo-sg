<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use RuntimeException;
use Throwable;

final class MyinfoV5TransportException extends RuntimeException implements JsonSerializable
{
    /** @var list<string> */
    private const array ENDPOINTS = ['discovery', 'par', 'token', 'jwks', 'userinfo'];

    public function __construct(
        private readonly string $endpoint,
        private readonly bool $restartAuthorization,
        #[\SensitiveParameter] ?Throwable $previous = null,
    ) {
        if (! in_array($endpoint, self::ENDPOINTS, true)) {
            throw new InvalidArgumentException('The MyInfo V5 transport endpoint category is invalid.');
        }

        parent::__construct('The MyInfo V5 transport request could not be completed.', 0, $previous);
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function restartAuthorization(): bool
    {
        return $this->restartAuthorization;
    }

    /** @return array{message: string, endpoint: string, restart_authorization: bool} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return array{message: string, endpoint: string, restart_authorization: bool} */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'endpoint' => $this->endpoint,
            'restart_authorization' => $this->restartAuthorization,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('MyInfo V5 transport exceptions cannot be serialized.');
    }
}
