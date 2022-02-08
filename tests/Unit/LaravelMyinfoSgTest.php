<?php

namespace Ziming\LaravelMyinfoSg\Tests\Feature\Http\Controllers;

use Illuminate\Support\Str;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSg;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class LaravelMyinfoSgTest extends TestCase
{
    private LaravelMyinfoSg $laravelMyinfoSg;

    public function setUp(): void
    {
        parent::setUp();
        $this->laravelMyinfoSg = new LaravelMyinfoSg;
    }

    public function testgenerateAuthoriseApiUrl()
    {
        $state = Str::random(40);
        $redirectUri = $this->laravelMyinfoSg->generateAuthoriseApiUrl($state);

        $this->assertStringStartsWith(config('laravel-myinfo-sg.api_authorise_url'), $redirectUri);

        // $response = $this->get($redirectUri);
        //$response->assertSuccessful();
        // $response->assertSeeText('Mock pass login');
    }
}
