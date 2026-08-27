<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\Requests;

use Illuminate\Support\Facades\Cache;
use Saloon\CachePlugin\Contracts\Cacheable;
use Saloon\CachePlugin\Contracts\Driver;
use Saloon\CachePlugin\Drivers\LaravelCacheDriver;
use Saloon\CachePlugin\Traits\HasCaching;
use Saloon\Enums\Method;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoV6Request;

class GetSingpassOpenIdConfigurationRequest extends MyinfoV6Request implements Cacheable
{
    use HasCaching;

    protected Method $method = Method::GET;

    public function __construct()
    {
        parent::__construct();
        $this->enableSafeReadRetries();
    }

    public function resolveEndpoint(): string
    {
        $issuerUri = rtrim(config('laravel-myinfo-sg-v6.issuer_uri'), '/');
        return $issuerUri.'/fapi/.well-known/openid-configuration';
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
