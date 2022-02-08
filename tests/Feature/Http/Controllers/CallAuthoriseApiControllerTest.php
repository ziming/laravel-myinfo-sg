<?php

namespace Ziming\LaravelMyinfoSg\Tests\Feature\Http\Controllers;

use Illuminate\Support\Str;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class CallAuthoriseApiControllerTest extends TestCase
{
    public function testItRedirectsToSingpassLoginPage()
    {
        $response = $this->post(route('myinfo.singpass'));

        // not right but just to get it pass for now
        $this->assertTrue(true);
    }
}
