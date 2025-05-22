<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\Requests;

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

    /**
     * @throws \JsonException
     */
    public function resolveEndpoint(): string
    {
        $getSingpassOpenIdConfigurationRequest = new GetSingpassOpenIdConfigurationRequest;
        $response = $getSingpassOpenIdConfigurationRequest->send();

        return $response->json('jwks_uri');
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
