<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit;

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

    public function testGenerateAuthoriseApiUrl()
    {
        $state = Str::random(40);
        $redirectUri = $this->laravelMyinfoSg->generateAuthoriseApiUrl($state);

        $this->assertStringStartsWith(config('laravel-myinfo-sg.api_authorise_url') . '?', $redirectUri);
        $this->assertStringContainsString('client_id=' . config('laravel-myinfo-sg.client_id'), $redirectUri);
        $this->assertStringContainsString('attributes=' . config('laravel-myinfo-sg.attributes'), $redirectUri);
        $this->assertStringContainsString('purpose=' . config('laravel-myinfo-sg.purpose'), $redirectUri);
        $this->assertStringContainsString('redirect_uri=' . config('laravel-myinfo-sg.redirect_url'), $redirectUri);

        $this->assertStringNotContainsString('client_secret', $redirectUri);
        // commented out below as it will just return 404 as they likely
        // did some check to see if it comes from a real browser

        // $response = $this->get($redirectUri);
        //$response->assertSuccessful();
        // $response->assertSeeText('Mock pass login');
    }
}
