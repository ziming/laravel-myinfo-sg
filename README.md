# Laravel MyInfo Singapore

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ziming/laravel-myinfo-sg.svg?style=flat-square)](https://packagist.org/packages/ziming/laravel-myinfo-sg)
[![Total Downloads](https://img.shields.io/packagist/dt/ziming/laravel-myinfo-sg.svg?style=flat-square)](https://packagist.org/packages/ziming/laravel-myinfo-sg)

A working PHP Laravel Package for MyInfo Singapore. With the annoying, 
time wasting hidden quirks of implementing it in PHP figured out. 

<a href="https://api.singpass.gov.sg/library/myinfo/v3/developers/overview" rel="noreferrer nofollow">Official MyInfo Docs</a>

## Contributing

A donation is always welcomed (currently $0), especially if you or your employer makes money with the help of my packages. Which I am aware of a couple.

## Is Myinfo v5 supported?

### Generate Authorization URI to Redirect to Singpass Myinfo Login Page

```php
$myinfoConnector = new MyinfoConnector;

$authoriseApiUrl = $myinfoConnector->generateAuthorizationUrl();

// If you want to change the redirect uri you can do this
$authoriseApiUrl = $myinfoConnector->generateAuthorizationUrl('https://www.the-redirect-uri-you-want-to-use.com/callback');

```

### After Singpass Redirect Back to Your Callback URI, Get MyInfo Person Data

```php

$myinfoConnector = new MyinfoConnector;

// If for some reason you need to change your redirect uri again. I cannot remember the use case as I took a very long break from this.
if (App::isLocal() === false) {
    $myinfoConnector
        ->oauthConfig()
        ->setRedirectUri(
            action(SomeControllerAction::class)
        );
}

$myinfoAuthenticator = $myinfoConnector->getAccessToken(
    $code,
    $state,
    session()->pull(config('laravel-myinfo-sg-v5.state_session_key')),
);

$personData = $myinfoConnector
    ->getUser($myinfoAuthenticator)
    ->json();
```

### The JWKS Endpoint

Either you make your own controller or you just generate it and paste it in Singpass API Portal.

Maybe in future I provide better support for it but for now I am drowned in work in a very small team. Sorry.

## What about Myinfo v6 with FAPI 2.0?

Yes. The package currently supports the Myinfo v6 / FAPI 2.0 flow.

The v6 connector handles these parts for you:

- OpenID discovery
- PAR (Pushed Authorization Request)
- PKCE
- DPoP
- client assertion signing
- userinfo JWE decryption
- userinfo JWS signature verification
- nonce verification on the userinfo response

The v6 flow is session-backed. Your authorization redirect route and callback route should run behind Laravel's `web` middleware so the package can keep:

- `state`
- `nonce`
- `code_verifier`
- the session-scoped DPoP key
- the effective redirect URI

### Publish Config

```bash
php artisan vendor:publish --provider="Ziming\LaravelMyinfoSg\LaravelMyinfoSgServiceProvider" --tag="myinfo-sg-config"
```

### Example `.env` For V6

```.dotenv
MYINFO_V6_ISSUER_URI=https://stg-id.singpass.gov.sg

MYINFO_V6_CLIENT_ID=your-client-id
MYINFO_V6_REDIRECT_URI=https://your-app.test/callback/myinfo-v6
MYINFO_V6_SCOPES=openid

# Full private JWKS used for client assertion signing and decrypting the userinfo response
MYINFO_V6_PRIVATE_JWKS='{"keys":[...]}'

# Matching public JWKS exposed to Singpass
MYINFO_V6_PUBLIC_JWKS='{"keys":[...]}'

# Select the signing key from the private JWKS used for client assertions
MYINFO_V6_CHOSEN_JWKS_SIG_KID=sig-your-key-id

# Optional package routes
MYINFO_V6_ENABLE_DEFAULT_AUTHORIZATION_REDIRECT_ROUTE=false
MYINFO_V6_CALL_AUTHORIZATION_API_URI=/redirect-to-singpass-v6

MYINFO_V6_ENABLE_DEFAULT_PUBLIC_JWKS_ENDPOINT_ROUTE=false
MYINFO_V6_PUBLIC_JWKS_URI=/sp/v6/jwks

MYINFO_V6_DEBUG_MODE=false
```

### Redirect The User To Singpass

If you enable the default authorization redirect route, you may point your button or form action at:

- `route('myinfo-v6.singpass')`

That route uses `Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\CallAuthorizationApiController` internally.

If you prefer to do it yourself, use the connector directly:

```php
<?php

use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;

$myinfoConnector = new MyinfoConnector;

return redirect()->to(
    $myinfoConnector->generateAuthorizationUrl()
);
```

If you need to override the redirect URI for this request only:

```php
<?php

use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;

$myinfoConnector = new MyinfoConnector;

return redirect()->to(
    $myinfoConnector->generateAuthorizationUrl(
        'https://your-app.test/callback/myinfo-v6'
    )
);
```

The package will automatically reuse that same redirect URI during the token exchange.

### Handle The Callback

You still need to define your own callback route and validate `state` before exchanging the authorization code.

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;

Route::get('/callback/myinfo-v6', function (Request $request) {
    $state = $request->string('state')->toString();
    $expectedState = session()->pull(
        config('laravel-myinfo-sg-v6.state_session_key')
    );

    abort_if($state === '' || $state !== $expectedState, 403, 'Invalid state');

    $code = $request->string('code')->toString();

    $myinfoConnector = new MyinfoConnector;

    $tokenResponse = $myinfoConnector->getAccessToken($code);

    $personData = $myinfoConnector
        ->getUser($tokenResponse['access_token'])
        ->json();

    return response()->json($personData);
})->middleware('web');
```

`getUser(...)->json()` returns the decrypted and verified Myinfo payload.

### Public JWKS Endpoint

If you enable the default public JWKS route, the package will expose:

- `route('myinfo-v6.public-jwks')`

That route uses `Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\PublicJwksController` and returns the value from `MYINFO_V6_PUBLIC_JWKS`.

If you prefer to register the routes yourself:

```php
<?php

use Illuminate\Support\Facades\Route;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\CallAuthorizationApiController;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\PublicJwksController;

Route::post('/redirect-to-singpass-v6', CallAuthorizationApiController::class)
    ->name('myinfo-v6.singpass')
    ->middleware('web');

Route::get('/sp/v6/jwks', PublicJwksController::class)
    ->name('myinfo-v6.public-jwks');
```

### Notes

- `MYINFO_V6_PRIVATE_JWKS` should be the full private JWKS.
- `MYINFO_V6_PUBLIC_JWKS` should be the matching public JWKS registered with Singpass.
- `MYINFO_V6_CHOSEN_JWKS_SIG_KID` should point at the signing key used for client assertions.
- The package generates a fresh ephemeral DPoP key per auth session automatically. You do not configure the DPoP key in `.env`.

## Installation (v3 instructions)

You can install the package via composer:

```bash
composer require ziming/laravel-myinfo-sg
```

Followed by adding the following variables to your `.env` file. 

The values provided below are the ones provided in the official MyInfo nodejs tutorial. 

Change them to the values you are given for your app.

```.dotenv
MYINFO_APP_CLIENT_ID=STG2-MYINFO-SELF-TEST
MYINFO_APP_CLIENT_SECRET=44d953c796cccebcec9bdc826852857ab412fbe2
MYINFO_APP_REDIRECT_URL=http://localhost:3001/callback
MYINFO_APP_PURPOSE="demonstrating MyInfo APIs"
MYINFO_APP_ATTRIBUTES=uinfin,name,sex,race,nationality,dob,email,mobileno,regadd,housingtype,hdbtype,marital,noa-basic,ownerprivate,cpfcontributions,cpfbalances

MYINFO_APP_SIGNATURE_CERT_PRIVATE_KEY=file:///Users/your-username/your-laravel-app/storage/myinfo-ssl/stg-demoapp-client-privatekey-2018.pem
MYINFO_SIGNATURE_CERT_PUBLIC_CERT=file:///Users/your-username/your-laravel-app/storage/myinfo-ssl/staging_myinfo_public_cert.cer

MYINFO_DEBUG_MODE=false

# SANDBOX ENVIRONMENT (no PKI digital signature)
MYINFO_AUTH_LEVEL=L0
MYINFO_API_AUTHORISE=https://sandbox.api.myinfo.gov.sg/com/v3/authorise
MYINFO_API_TOKEN=https://sandbox.api.myinfo.gov.sg/com/v3/token
MYINFO_API_PERSON=https://sandbox.api.myinfo.gov.sg/com/v3/person

# TEST ENVIRONMENT (with PKI digital signature)
#MYINFO_AUTH_LEVEL=L2
#MYINFO_API_AUTHORISE=https://test.api.myinfo.gov.sg/com/v3/authorise
#MYINFO_API_TOKEN=https://test.api.myinfo.gov.sg/com/v3/token
#MYINFO_API_PERSON=https://test.api.myinfo.gov.sg/com/v3/person

# Controller URI Paths. IMPORTANT
MYINFO_CALL_AUTHORISE_API_URL=/redirect-to-singpass
MYINFO_GET_PERSON_DATA_URL=/myinfo-person
```

Lastly, publish the config file

```bash
php artisan vendor:publish --provider="Ziming\LaravelMyinfoSg\LaravelMyinfoSgServiceProvider" --tag="myinfo-sg-config"
```

You may also wish to publish the MyInfo official nodejs demo app ssl files as well to storage/myinfo-ssl. 
You should replace these in your production environment.

```bash
php artisan vendor:publish --provider="Ziming\LaravelMyinfoSg\LaravelMyinfoSgServiceProvider" --tag="myinfo-ssl"
```

## Usage and Customisations

When building your button to redirect to SingPass. It should link to `route('myinfo.singpass')`

After SingPass redirects back to your Callback URI, you should make a post request to `route('myinfo.person')`

If you prefer to not use the default routes provided you may set `enable_default_myinfo_routes` to `false` in 
`config/laravel-myinfo-sg.php` and map your own routes. This package controllers will still be accessible as shown
in the example below:

```php
<?php
use Ziming\LaravelMyinfoSg\Http\Controllers\CallAuthoriseApiController;
use Ziming\LaravelMyinfoSg\Http\Controllers\GetMyinfoPersonDataController;
use Illuminate\Support\Facades\Route;

Route::post('/go-singpass'), CallAuthoriseApiController::class)
->name('myinfo.singpass')
->middleware('web');

Route::post('/fetch-myinfo-person-data', GetMyinfoPersonDataController::class)
->name('myinfo.person');
```

During the entire execution, some exceptions may be thrown. If you do not like the format of the json responses.
You can customise it by intercepting them in your laravel application `app/Exceptions/Handler.php`

An example is shown below:

```php
<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Ziming\LaravelMyinfoSg\Exceptions\AccessTokenNotFoundException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        // You may wish to add all the Exceptions thrown by this package. See src/Exceptions folder
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function report(\Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, \Throwable $exception)
    {
        // Example of an override. You may override it via Service Container binding too
        if ($exception instanceof AccessTokenNotFoundException && $request->wantsJson()) {
            return response()->json([
                'message' => 'Access Token is missing'
            ], 404);
        }
        
        return parent::render($request, $exception);
    }
}
```

The list of exceptions are as follows

```php
<?php
use Ziming\LaravelMyinfoSg\Exceptions\AccessTokenNotFoundException;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidAccessTokenException;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidDataOrSignatureForPersonDataException;
use Ziming\LaravelMyinfoSg\Exceptions\InvalidStateException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoPersonDataNotFoundException;
use Ziming\LaravelMyinfoSg\Exceptions\SubNotFoundException;
```

Lastly, if you prefer to write your own controllers, you may make use of `LaravelMyinfoSgFacade` or `LaravelMyinfoSg` to generate the
authorisation api uri (The redirect to Singpass link) and to fetch MyInfo Person Data. Examples are shown below

```php
<?php

use Ziming\LaravelMyinfoSg\LaravelMyinfoSgFacade as LaravelMyinfoSg;

// Get the Singpass URI and redirect to there
return redirect(LaravelMyinfoSg::generateAuthoriseApiUrl($state));
```

```php
<?php
use Ziming\LaravelMyinfoSg\LaravelMyinfoSgFacade as LaravelMyinfoSg;

// Get the Myinfo person data in an array with 'data' key
$personData = LaravelMyinfoSg::getMyinfoPersonData($code);

// If you didn't want to return a json response with the person information in the 'data' key. You can do this
return response()->json($personData['data']);
```

You may also choose to subclass `GetMyinfoPersonDataController` and override its `preResponseHook()` template method to
do logging or other stuffs before returning the person data.

### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.
