<?php

declare (strict_types=1);

namespace Ziming\LaravelMyinfoSg\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * @internal
 * Untested, just merely writing it based on initial reading the docs, could be very wrong
 * Previously I used another tool to generate them
 */
class GenerateJwkSetCommand extends Command
{
    protected $signature = 'myinfo:generate-jwks';

    protected $description = 'Generate JWKS (Json Web Key Sets) Command';

    public function handle(): int
    {
        $currentDateTime = Carbon::now()->toISOString();

        $sigJwk = JWKFactory::createECKey(
            'P-256', // Key size in bits
            [
                'alg' => 'ES256',
                'use' => 'sig',
                'kid' => 'sig-'.$currentDateTime,
            ]
        );

        $encJwk = JWKFactory::createECKey(
            'P-256',
            [
                'alg' => 'ES256',
                'use' => 'enc',
                'kid' => 'enc-'.$currentDateTime,
            ]
        );

        $jwkSet = new JWKSet([$sigJwk, $encJwk]);

        $jwksArray = $jwkSet->all();

        $jwks = json_encode(['keys' => $jwksArray], JSON_PRETTY_PRINT);

        $this->line('Pretty Printed Json');
        $this->info($jwks);

        $this->line('--------');

        $this->line('Non Pretty Printed Json, If you prefer to have it in your env file for example');
        $this->info(json_encode($jwksArray));

        return self::SUCCESS;
    }
}
