<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests;

use Illuminate\Support\Facades\Cache;
use Saloon\CachePlugin\Contracts\Cacheable;
use Saloon\CachePlugin\Contracts\Driver;
use Saloon\CachePlugin\Drivers\LaravelCacheDriver;
use Saloon\CachePlugin\Traits\HasCaching;
use Saloon\Enums\Method;
use Saloon\Http\SoloRequest;

class GetSingpassJwksRequest extends SoloRequest implements Cacheable
{
    use HasCaching;

    protected Method $method = Method::GET;

    public function __construct(private string $jwksUri)
    {
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
