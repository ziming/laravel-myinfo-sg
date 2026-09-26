<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests;

use Illuminate\Support\Facades\Cache;
use Saloon\CachePlugin\Contracts\Cacheable;
use Saloon\CachePlugin\Contracts\Driver;
use Saloon\CachePlugin\Drivers\LaravelCacheDriver;
use Saloon\CachePlugin\Traits\HasCaching;
use Saloon\Enums\Method;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\MyinfoV5Request;

class GetSingpassJwksRequest extends MyinfoV5Request implements Cacheable
{
    use HasCaching;

    protected Method $method = Method::GET;

    public function __construct(private string $jwksUri)
    {
        parent::__construct();
        $this->enableSafeReadRetries();
    }

    public function resolveEndpoint(): string
    {
        return $this->jwksUri;
    }

    public function resolveCacheDriver(): Driver
    {
        return new LaravelCacheDriver(
            Cache::store(
                config('cache.default')
            )
        );
    }

    public function cacheExpiryInSeconds(): int
    {
        return 3600;
    }
}
