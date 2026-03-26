<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit\MyinfoV6;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\CallAuthorizationApiController;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class CallAuthorizationApiControllerTest extends TestCase
{
    public function test_it_redirects_to_the_generated_authorization_url(): void
    {
        $controller = new CallAuthorizationApiController;

        $connector = new class extends MyinfoConnector
        {
            public function generateAuthorizationUrl(?string $redirectUri = null): string
            {
                return 'https://stg-id.singpass.gov.sg/fapi/authorize?request_uri=urn%3Aexample';
            }
        };

        $response = $controller(
            $connector,
            $this->app->make(Redirector::class)
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            'https://stg-id.singpass.gov.sg/fapi/authorize?request_uri=urn%3Aexample',
            $response->getTargetUrl()
        );
    }
}
