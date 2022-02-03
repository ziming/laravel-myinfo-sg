<?php

namespace Ziming\LaravelMyinfoSg;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Ziming\LaravelMyinfoSg\LaravelMyinfoSg
 */
class LaravelMyinfoSgFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-myinfo-sg';
    }
}
