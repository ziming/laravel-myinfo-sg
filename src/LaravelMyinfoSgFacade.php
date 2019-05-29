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
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'laravel-myinfo-sg';
    }
}
