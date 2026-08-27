<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Utils;

/*
 * @internal
 *
 * Fetches Myinfo attributes from a decoded V5 UserInfo claim set. The raw V5
 * shape keeps person attributes under `person_info`, while the inherited
 * accessors continue to operate on the unchanged Myinfo Get Person shape.
 */
final class MyinfoV5RawValueFetcher extends MyinfoValueFetcher
{
    /**
     * @param array<string, mixed> $rawUserInfo
     */
    private function __construct(array $rawUserInfo)
    {
        $personInfo = $rawUserInfo['person_info'] ?? [];

        parent::__construct(is_array($personInfo) ? $personInfo : []);
    }

    /**
     * @param array<string, mixed> $rawUserInfo
     */
    public static function make(array $rawUserInfo): self
    {
        return new self($rawUserInfo);
    }
}
