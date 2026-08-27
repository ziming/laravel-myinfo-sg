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
- ID token JWE decryption
- ID token JWS signature verification
- ID token issuer, audience, expiry, issued-at, and nonce verification
- UserInfo JWE decryption and JWS signature verification
- UserInfo issuer, audience, issued-at, subject, and `person_info` verification
- ID-token-to-UserInfo subject binding

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

# Full private JWKS used for client assertion signing and decrypting ID token/userinfo responses
MYINFO_V6_PRIVATE_JWKS='{"keys":[...]}'

# Matching public JWKS exposed to Singpass
MYINFO_V6_PUBLIC_JWKS='{"keys":[...]}'

# Select the signing key from the private JWKS used for client assertions
MYINFO_V6_CHOSEN_JWKS_SIG_KID=sig-your-key-id

# Select the ephemeral DPoP signing profile (ES256, ES384, or ES512; defaults to ES256)
MYINFO_V6_DPOP_SIGNING_ALG=ES256

# Outbound transport limits and safe-read retry policy
MYINFO_V6_CONNECT_TIMEOUT_SECONDS=5
MYINFO_V6_REQUEST_TIMEOUT_SECONDS=15
MYINFO_V6_SAFE_READ_MAX_ATTEMPTS=2
MYINFO_V6_SAFE_READ_RETRY_DELAY_MILLISECONDS=200

# Optional package routes
MYINFO_V6_ENABLE_DEFAULT_AUTHORIZATION_REDIRECT_ROUTE=false
MYINFO_V6_CALL_AUTHORIZATION_API_URI=/redirect-to-singpass-v6

MYINFO_V6_ENABLE_DEFAULT_PUBLIC_JWKS_ENDPOINT_ROUTE=false
MYINFO_V6_PUBLIC_JWKS_URI=/sp/v6/jwks

MYINFO_V6_DEBUG_MODE=false
```

### V6 Transport Recovery

Every v6 request uses the configured connection and overall request timeouts. Safe-read attempts are total
attempts, including the first request, and must be between 1 and 3. The retry delay must be between 0 and
5,000 milliseconds.

| Endpoint | Attempts | Automatically retried failures |
|---|---:|---|
| OpenID discovery | `MYINFO_V6_SAFE_READ_MAX_ATTEMPTS` | Connection failures, `429`, `502`, `503`, `504` |
| Singpass JWKS | `MYINFO_V6_SAFE_READ_MAX_ATTEMPTS` | Connection failures, `429`, `502`, `503`, `504` |
| UserInfo | `MYINFO_V6_SAFE_READ_MAX_ATTEMPTS` | Connection failures, `429`, `502`, `503`, `504`; a fresh DPoP proof and `jti` are generated for every attempt |
| PAR | 1 | Never automatically retried |
| Token exchange | 1 | Never automatically retried |

Connection failures and exhausted retryable responses throw
`Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\MyinfoV6TransportException`. Its `endpoint()` method returns a
safe endpoint category, and `restartAuthorization()` tells the application how to recover. When
`restartAuthorization()` is `true`, the authorization outcome is ambiguous: discard that attempted flow
and start a new authorization. Never replay its old authorization code, client assertion, or DPoP proof.
When it is `false`, an application may retry the operation later with the retained `VerifiedTokenSet`; the
package will generate a new UserInfo DPoP proof.

```php
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\MyinfoV6TransportException;

try {
    $tokenSet = $myinfoConnector->completeAuthorization($request);
    $userInfo = $myinfoConnector->getVerifiedUserInfo($tokenSet);
} catch (MyinfoV6TransportException $exception) {
    if ($exception->restartAuthorization()) {
        return redirect()->route('myinfo-v6.singpass');
    }

    return response()->json(['message' => 'Singpass is temporarily unavailable.'], 503);
}
```

Singpass discovery and JWKS responses remain normally cached for one hour. If ID-token or UserInfo
verification finds an unknown signing key or a bad signature, the package invalidates the cached Singpass
JWKS and refreshes it exactly once before returning the existing sanitized invalid-token error. Decryption,
algorithm, nonce, claim, and subject failures do not trigger a JWKS refresh.

### Generate JWKS

Generate the initial signing and encryption key pairs with:

```bash
php artisan myinfo:generate-jwks \
    --private-output=storage/app/private/myinfo/private.jwks.json \
    --public-output=storage/app/myinfo/public.jwks.json
```

Add `--configure` for a guided, step-by-step choice of the signing algorithm, encryption algorithm, and
encryption curve:

```bash
php artisan myinfo:generate-jwks --configure \
    --private-output=storage/app/private/myinfo/private.jwks.json \
    --public-output=storage/app/myinfo/public.jwks.json
```

For non-interactive scripts, pass the choices directly:

```bash
php artisan myinfo:generate-jwks \
    --signing-alg=ES384 \
    --encryption-alg=ECDH-ES+A192KW \
    --encryption-curve=P-384 \
    --private-output=storage/app/private/myinfo/private.jwks.json \
    --public-output=storage/app/myinfo/public.jwks.json
```

Supported signing combinations:

| Signing algorithm | Curve |
| --- | --- |
| `ES256` (default) | `P-256` |
| `ES384` | `P-384` |
| `ES512` | `P-521` |

Supported encryption algorithms are `ECDH-ES+A128KW` (default), `ECDH-ES+A192KW`, and
`ECDH-ES+A256KW`. Each can be used with `P-256` (default), `P-384`, or `P-521`, as permitted by the
[Singpass JWKS requirements](https://docs.developer.singpass.gov.sg/docs/technical-specifications/technical-concepts/json-web-key-sets-jwks).

#### Select the DPoP signing profile

`MYINFO_V6_DPOP_SIGNING_ALG` independently selects the algorithm for the ephemeral DPoP key. It does
not select the registered client-assertion key controlled by `MYINFO_V6_CHOSEN_JWKS_SIG_KID`.

| DPoP algorithm | Required curve |
| --- | --- |
| `ES256` (default) | `P-256` |
| `ES384` | `P-384` |
| `ES512` | `P-521` |

The algorithm determines the curve; there is no separate DPoP curve setting. Discovery metadata may
reject the selected local profile but cannot enable any profile outside this table.

The package generates a fresh ephemeral DPoP private key for every authorization transaction. That exact
key and algorithm are retained for the transaction and reused across PAR, token exchange, and UserInfo,
even if configuration changes after PAR. Every HTTP request still receives a newly signed proof with a
fresh `jti`; only the UserInfo proof includes the access-token hash in `ath`. DPoP keys are never reused
between transactions.

When working directly in this package repository, replace `php artisan` with `vendor/bin/testbench`.

The private file is created with owner-only permissions (`0600`). Keep it outside the public web root and
store its contents in `MYINFO_V6_PRIVATE_JWKS` or an appropriate secrets manager. The public file contains
matching public keys without the private `d` property and can be used for `MYINFO_V6_PUBLIC_JWKS` or the
public JWKS endpoint. The command also prints the generated `MYINFO_V6_CHOSEN_JWKS_SIG_KID` value.

If `--public-output` is omitted, the public JWKS is printed as a ready-to-copy environment assignment.
Private key material is never printed unless you explicitly use `--show-private`; only use that option in a
trusted local terminal and never in CI or captured logs. Existing files are not overwritten unless `--force`
is supplied.

Validate the complete private/public pair before deployment, optionally checking the configured signing
key selection:

```bash
php artisan myinfo:validate-jwks \
    --private=storage/app/private/myinfo/private.jwks.json \
    --public=storage/app/myinfo/public.jwks.json \
    --signing-kid="$MYINFO_V6_CHOSEN_JWKS_SIG_KID"
```

#### Rotate JWKS

Rotate signing and encryption keys at least annually. The guided rotation command always reads a complete,
validated pair and creates new complete JWKS files at distinct paths; it never overwrites its inputs.

These commands only produce local artifacts. They do not publish or deploy a JWKS, update `.env`, select a
signing key, contact Singpass, or update a partner portal. Put private output into a secrets manager. Only the
public output belongs at the registered public JWKS endpoint.

For signing-key rotation, prepare an old+new signing-key overlap (replace the example `kid` values and paths):

```bash
php artisan myinfo:rotate-jwks \
    --stage=prepare \
    --role=signing \
    --replace-kid=sig-old-kid \
    --private-input=storage/app/private/myinfo/private.jwks.json \
    --public-input=storage/app/myinfo/public.jwks.json \
    --private-output=storage/app/private/myinfo/signing-overlap.private.jwks.json \
    --public-output=storage/app/myinfo/signing-overlap.public.jwks.json
```

Deploy the prepared private overlap to the secrets manager while keeping the old signing `kid` selected.
Publish the prepared public set containing the old and new signing keys, wait at least one hour for the
Singpass JWKS cache, and then change `MYINFO_V6_CHOSEN_JWKS_SIG_KID` to the new `kid` printed by the command.
After the new signing key is active, retire the old key into another new pair:

```bash
php artisan myinfo:rotate-jwks \
    --stage=finalize \
    --role=signing \
    --replace-kid=sig-old-kid \
    --active-signing-kid=sig-new-kid \
    --confirm-cache-expired \
    --private-input=storage/app/private/myinfo/signing-overlap.private.jwks.json \
    --public-input=storage/app/myinfo/signing-overlap.public.jwks.json \
    --private-output=storage/app/private/myinfo/signing-final.private.jwks.json \
    --public-output=storage/app/myinfo/signing-final.public.jwks.json
```

For encryption-key rotation, prepare a private old+new overlap and a public set containing only the new
encryption key (unrelated keys are retained):

```bash
php artisan myinfo:rotate-jwks \
    --stage=prepare \
    --role=encryption \
    --replace-kid=enc-old-kid \
    --private-input=storage/app/private/myinfo/private.jwks.json \
    --public-input=storage/app/myinfo/public.jwks.json \
    --private-output=storage/app/private/myinfo/encryption-overlap.private.jwks.json \
    --public-output=storage/app/myinfo/encryption-new.public.jwks.json
```

Deploy the private overlap first so responses encrypted to either key can be decrypted. Then publish the new
public set, wait at least one hour, and finalize removal of the old private encryption key:

```bash
php artisan myinfo:rotate-jwks \
    --stage=finalize \
    --role=encryption \
    --replace-kid=enc-old-kid \
    --confirm-cache-expired \
    --private-input=storage/app/private/myinfo/encryption-overlap.private.jwks.json \
    --public-input=storage/app/myinfo/encryption-new.public.jwks.json \
    --private-output=storage/app/private/myinfo/encryption-final.private.jwks.json \
    --public-output=storage/app/myinfo/encryption-final.public.jwks.json
```

Without `--confirm-cache-expired`, finalize asks interactively whether the one-hour cache window elapsed.
Non-interactive deployment pipelines must pass the flag explicitly; it records operator confirmation and
does not attempt to infer deployment history.

The required state transitions are:

- Signing: publish old+new public keys, wait one hour, switch the signing `kid`, then retire the old public
  and private key.
- Encryption: retain old+new private keys, publish the new public key, wait one hour, then retire the old
  private key.

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

The package stores each authorization attempt as a separate, session-bound transaction for 10 minutes.
That means starting another authorization in a second tab does not overwrite the first tab's state,
PKCE verifier, redirect URI, nonce, issuer, or DPoP key.

### Handle The Callback

You still need to define your own callback route. Use `completeAuthorization()` followed by
`getVerifiedUserInfo()` as the secure completion flow. It validates and consumes the transaction-scoped
`state`, compares callback `iss` exactly, exchanges the code with that transaction's PKCE and DPoP
context, and verifies the ID token. The UserInfo request then reuses that same transaction DPoP key,
includes the access-token hash in `ath`, verifies the response, and requires its `sub` to match the
verified ID-token subject.

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV6\MyinfoConnector;

Route::get('/callback/myinfo-v6', function (Request $request) {
    $myinfoConnector = new MyinfoConnector;
    $tokenSet = $myinfoConnector->completeAuthorization($request);
    $userInfo = $myinfoConnector->getVerifiedUserInfo($tokenSet);

    return response()->json($userInfo->personInfo());
})->middleware('web');
```

The returned `VerifiedTokenSet` exposes the access token through `accessToken()`, the verified ID-token
claims through `claims()`, the trusted subject through `subject()`, and the exact `DPoP` token type through
`tokenType()`. Its access token and transaction-bound private DPoP key are excluded from debug and JSON
output, and the object cannot be serialized.

`getVerifiedUserInfo()` returns a `VerifiedUserInfo` DTO. Its `claims()` method exposes the full verified
claim set, `subject()` exposes the subject that was matched to the ID token, and `personInfo()` returns the
typed `person_info` array. UserInfo requires `person_info`, `iss`, `iat`, `sub`, and `aud`. Its `exp` claim
is optional, but is validated when present.

The authorization `nonce` is verified only in the ID token. UserInfo does not carry or require a nonce;
it is bound to the authenticated session by matching its `sub` to the verified ID-token subject. Time-based
ID-token and UserInfo checks use a fixed two-second clock-skew allowance. Beyond that allowance, the current
time must be before any applicable `exp`, and `iat` must not be in the future.

`getAccessToken(string $code)` and `getAccessTokenFromValidatedCallback()` remain available as low-level
compatibility methods. They return raw, unverified token-endpoint data. The string-only method also cannot
validate callback `state` or `iss`. Do not treat either result as authenticated or use either method as the
primary callback path in new integrations.

`getUser(string $accessToken)` is also a low-level compatibility method. It verifies the UserInfo signature
and required claim shapes through the shared processor, but a bare access-token string cannot prove that its
UserInfo `sub` matches a verified ID token. Use `getVerifiedUserInfo($tokenSet)` for subject-bound data.

### Public JWKS Endpoint

If you enable the default public JWKS route, the package will expose:

- `route('myinfo-v6.public-jwks')`

That route uses `Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\PublicJwksController` and returns the value from `MYINFO_V6_PUBLIC_JWKS`.

The endpoint validates this configuration before building a response. It fails closed if the public JWKS
contains private `d` material, duplicate key IDs, unsupported algorithms or curves, or does not contain at
least one signing key and one encryption key. It never repairs a private JWKS by silently removing `d`.

If private key material may already have been served from this endpoint, rotate every affected signing or
encryption key and update the registered public JWKS. Correcting `MYINFO_V6_PUBLIC_JWKS` alone is not
sufficient because the exposed private key must be treated as compromised.

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
- The package generates a fresh ephemeral DPoP key per authorization transaction. You configure only its
  algorithm with `MYINFO_V6_DPOP_SIGNING_ALG`, never the key material itself.
- Authorization transactions are stored in the user's Laravel session under `transaction_session_key`
  and expire after `transaction_ttl_seconds` (600 seconds by default).

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
