# Laravel MyInfo Singapore

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ziming/laravel-myinfo-sg.svg?style=flat-square)](https://packagist.org/packages/ziming/laravel-myinfo-sg)
[![Build Status](https://img.shields.io/travis/ziming/laravel-myinfo-sg/master.svg?style=flat-square)](https://travis-ci.org/ziming/laravel-myinfo-sg)
[![Quality Score](https://img.shields.io/scrutinizer/g/ziming/laravel-myinfo-sg.svg?style=flat-square)](https://scrutinizer-ci.com/g/ziming/laravel-myinfo-sg)
[![Total Downloads](https://img.shields.io/packagist/dt/ziming/laravel-myinfo-sg.svg?style=flat-square)](https://packagist.org/packages/ziming/laravel-myinfo-sg)

A PHP Laravel Package for MyInfo Singapore. Although it should work, the core API is not fully stable yet so use it at your own **risk**.

Tests will be welcomed.

## Installation

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
MYINFO_APP_REDIRECT_URI=http://localhost:3001/callback
MYINFO_APP_ATTRIBUTES=uinfin,name,sex,race,nationality,dob,email,mobileno,regadd,housingtype,hdbtype,marital,edulevel,noa-basic,ownerprivate,cpfcontributions,cpfbalances

# Absolute Path starting with file://
MYINFO_APP_SIGNATURE_CERT_PRIVATE_KEY=file://Users/your-username/your-laravel-app/storage/myinfo-ssl/stg-demoapp-client-privatekey-2018.pem
MYINFO_SIGNATURE_CERT_PUBLIC_CERT=file://Users/your-username/your-laravel-app/storage/myinfo-ssl/stg-auth-signing-public.pem

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
MYINFO_CALL_AUTHORISE_API_URI=/redirect-to-singpass
MYINFO_GET_PERSON_DATA_URI=/myinfo-person
```

Lastly, publish the config file

```bash
php artisan vendor:publish --provider="Ziming\LaravelMyinfoSg\LaravelMyinfoSgServiceProvider" --tag="config"
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

Route::get(config('/go-singpass'), CallAuthoriseApiController::class)
->name('myinfo.singpass')
->middleware(['auth']); // You may add your middlewares here

Route::post('/fetch-myinfo-person-data', GetMyinfoPersonDataController::class)
->name('myinfo.person')
->middleware(['auth']);
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
     * @param  \Exception  $exception
     * @return void
     */
    public function report(Exception $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $exception)
    {
        // Example of an override.
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
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoPersonDataNotFoundException;
use Ziming\LaravelMyinfoSg\Exceptions\UinfinNotFoundException;
```

Lastly, if you prefer to write your own controllers, you may make use of `LaravelMyinfoSgFacade` to generate the
authorisation api uri (The redirect to Singpass link) and to fetch MyInfo Person Data. Examples are shown below

```php
<?php

use Ziming\LaravelMyinfoSg\LaravelMyinfoSgFacade as LaravelMyinfoSg;

// Get the Singpass URI and redirect to there
return redirect(LaravelMyinfoSg::generateAuthoriseApiUri());
```

```php
<?php

// Get the Myinfo person data in an array with 'data' key
$personData = LaravelMyinfoSg::getMyinfoPersonData();

// If you didn't want to return a json response with the person information in the 'data' key. You can do this
return response()->json($personData['data']);
```

### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Ziming](https://github.com/ziming)
- [All Contributors](../../contributors)