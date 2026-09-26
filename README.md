# Laravel MyInfo Singapore

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ziming/laravel-myinfo-sg.svg?style=flat-square)](https://packagist.org/packages/ziming/laravel-myinfo-sg)
[![Total Downloads](https://img.shields.io/packagist/dt/ziming/laravel-myinfo-sg.svg?style=flat-square)](https://packagist.org/packages/ziming/laravel-myinfo-sg)

A Laravel package for integrating with Singapore's MyInfo through Singpass FAPI 2.0. It provides a
session-backed authorization flow, verified identity and personal-data responses, and JWKS management commands.

[Official Singpass documentation](https://docs.developer.singpass.gov.sg/docs)

## Contents

- [Requirements](#requirements)
- [Supported integration](#supported-integration)
- [Installation](#installation)
- [Configuration](#configuration)
- [Redirect the user to Singpass](#redirect-the-user-to-singpass)
- [Handle the callback](#handle-the-callback)
- [Public JWKS endpoint](#public-jwks-endpoint)
- [Errors and transport recovery](#errors-and-transport-recovery)
- [Generate JWKS](#generate-jwks)
- [Rotate JWKS](#rotate-jwks)
- [Development](#development)

## Requirements

- PHP 8.4 or later within the PHP 8.x series, with the GMP, JSON, and OpenSSL extensions.
- Laravel 12 or 13.
- A Singpass application with a client ID, registered redirect URI, approved scopes, and registered public JWKS.
- A persistent Laravel session shared by the authorization and callback routes.

## Supported integration

This guide covers the FAPI 2.0 implementation in the `MyinfoV5` namespace, configured through
`laravel-myinfo-sg-v5`.

> **Version naming:** In this checkout, `MyinfoV5` refers to the FAPI 2.0 integration. Earlier package
> releases used `MyinfoV6` for this flow and `MyinfoV5` for the previous implementation. When using an
> older release, consult the README shipped with that release; namespaces and configuration may differ.

The connector handles these parts for you:

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

The flow is session-backed. Your authorization redirect route and callback route should run behind Laravel's `web` middleware so the package can keep:

- `state`
- `nonce`
- `code_verifier`
- the session-scoped DPoP key
- the effective redirect URI

## Installation

```bash
composer require ziming/laravel-myinfo-sg
```

Laravel discovers the service provider automatically. Publish the package configuration:

```bash
php artisan vendor:publish --provider="Ziming\LaravelMyinfoSg\LaravelMyinfoSgServiceProvider" --tag="myinfo-sg-config"
```

The FAPI 2.0 integration uses `config/laravel-myinfo-sg-v5.php`.

## Configuration

Set your Singpass application's client ID, registered callback URI, and approved scopes. Scopes are
space-separated; include `openid` and the MyInfo scopes approved for your application. The example uses
the package's staging issuer default.

Generate your keys using [Generate JWKS](#generate-jwks), then replace both JWKS placeholders below with
the complete JSON contents of the generated files. These settings accept JSON strings, not file paths.
Register the matching public JWKS or its public URL in your Singpass app configuration.

### Environment variables

```dotenv
MYINFO_V5_ISSUER_URI=https://stg-id.singpass.gov.sg

MYINFO_V5_CLIENT_ID=your-client-id
MYINFO_V5_REDIRECT_URI=https://your-app.test/callback/myinfo-v5
MYINFO_V5_SCOPES=openid

# Full private JWKS used for client assertion signing and decrypting ID token/userinfo responses
MYINFO_V5_PRIVATE_JWKS='{"keys":[...]}'

# Matching public JWKS exposed to Singpass
MYINFO_V5_PUBLIC_JWKS='{"keys":[...]}'

# Select the signing key from the private JWKS used for client assertions
MYINFO_V5_CHOSEN_JWKS_SIG_KID=sig-your-key-id

# Select the ephemeral DPoP signing profile (ES256, ES384, or ES512; defaults to ES256)
MYINFO_V5_DPOP_SIGNING_ALG=ES256

# Outbound transport limits and safe-read retry policy
MYINFO_V5_CONNECT_TIMEOUT_SECONDS=5
MYINFO_V5_REQUEST_TIMEOUT_SECONDS=15
MYINFO_V5_SAFE_READ_MAX_ATTEMPTS=2
MYINFO_V5_SAFE_READ_RETRY_DELAY_MILLISECONDS=200

# Optional package routes
MYINFO_V5_ENABLE_DEFAULT_AUTHORIZATION_REDIRECT_ROUTE=false
MYINFO_V5_CALL_AUTHORIZATION_API_URI=/redirect-to-singpass-v5

MYINFO_V5_ENABLE_DEFAULT_PUBLIC_JWKS_ENDPOINT_ROUTE=false
MYINFO_V5_PUBLIC_JWKS_URI=/myinfo/v5/jwks

MYINFO_V5_DEBUG_MODE=false
```

After changing environment values in a deployment that caches configuration, rebuild the cache with
`php artisan config:cache`. The generated key files are not loaded automatically by the package.

## Redirect the user to Singpass

Enable `MYINFO_V5_ENABLE_DEFAULT_AUTHORIZATION_REDIRECT_ROUTE=true` to use the package's
`POST /redirect-to-singpass-v5` route. Submit a Blade form with a CSRF token:

```blade
<form method="POST" action="{{ route('myinfo-v5.singpass') }}">
    @csrf
    <button type="submit">Continue with Singpass</button>
</form>
```

The route accepts POST requests, so a normal hyperlink or HTTP redirect to it will not work.

That route uses `Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV5\CallAuthorizationApiController` internally.

For a custom controller or route in `routes/web.php`, use the connector directly:

```php
<?php

use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\MyinfoConnector;

$myinfoConnector = new MyinfoConnector;

return redirect()->to(
    $myinfoConnector->generateAuthorizationUrl()
);
```

If you need to override the redirect URI for this request only:

```php
<?php

use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\MyinfoConnector;

$myinfoConnector = new MyinfoConnector;

return redirect()->to(
    $myinfoConnector->generateAuthorizationUrl(
        'https://your-app.test/callback/myinfo-v5'
    )
);
```

The package stores each authorization attempt as a separate, session-bound transaction for 10 minutes
by default. Adjust `transaction_ttl_seconds` in `config/laravel-myinfo-sg-v5.php` if needed.
That means starting another authorization in a second tab does not overwrite the first tab's state,
PKCE verifier, redirect URI, nonce, issuer, or DPoP key.

## Handle the callback

Define your own callback route in `routes/web.php`, matching `MYINFO_V5_REDIRECT_URI`. Use `completeAuthorization()` followed by
`getVerifiedUserInfo()` as the secure completion flow. It validates and consumes the transaction-scoped
`state`, compares callback `iss` exactly, exchanges the code with that transaction's PKCE and DPoP
context, and verifies the ID token. The UserInfo request then reuses that same transaction DPoP key,
includes the access-token hash in `ath`, verifies the response, and requires its `sub` to match the
verified ID-token subject.

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\MyinfoConnector;

Route::get('/callback/myinfo-v5', function (Request $request) {
    $myinfoConnector = new MyinfoConnector;
    $tokenSet = $myinfoConnector->completeAuthorization($request);
    $userInfo = $myinfoConnector->getVerifiedUserInfo($tokenSet);

    return response()->json($userInfo->personInfo());
});
```

Routes in `routes/web.php` already receive Laravel's `web` middleware. If you register these routes
elsewhere, apply it to both the authorization and callback routes so they share the same session.
The JSON response above is a minimal example; use `personInfo()` in your application's own workflow.

### Verified responses

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

### Low-level compatibility methods

`getAccessToken(string $code)` and `getAccessTokenFromValidatedCallback()` remain available as low-level
compatibility methods. They return raw, unverified token-endpoint data. The string-only method also cannot
validate callback `state` or `iss`. Do not treat either result as authenticated or use either method as the
primary callback path in new integrations.

`getUser(string $accessToken)` is also a low-level compatibility method. It verifies the UserInfo signature
and required claim shapes through the shared processor, but a bare access-token string cannot prove that its
UserInfo `sub` matches a verified ID token. Use `getVerifiedUserInfo($tokenSet)` for subject-bound data.

## Public JWKS endpoint

Set `MYINFO_V5_ENABLE_DEFAULT_PUBLIC_JWKS_ENDPOINT_ROUTE=true` to expose `GET /myinfo/v5/jwks`:

- `route('myinfo-v5.public-jwks')`

That route uses `Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV5\PublicJwksController` and returns the value from `MYINFO_V5_PUBLIC_JWKS`.

The endpoint validates this configuration before building a response. It fails closed if the public JWKS
contains private `d` material, duplicate key IDs, unsupported algorithms or curves, or does not contain at
least one signing key and one encryption key. It never repairs a private JWKS by silently removing `d`.

If private key material may already have been served from this endpoint, rotate every affected signing or
encryption key and update the registered public JWKS. Correcting `MYINFO_V5_PUBLIC_JWKS` alone is not
sufficient because the exposed private key must be treated as compromised.

If you prefer to register the routes yourself:

```php
<?php

use Illuminate\Support\Facades\Route;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV5\CallAuthorizationApiController;
use Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV5\PublicJwksController;

Route::post('/redirect-to-singpass-v5', CallAuthorizationApiController::class)
    ->name('myinfo-v5.singpass')
    ->middleware('web');

Route::get('/myinfo/v5/jwks', PublicJwksController::class)
    ->name('myinfo-v5.public-jwks');
```

## Errors and transport recovery

Handle callback and verification errors in your application's exception handler or callback controller:

| Exception in `Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5` | Meaning |
| --- | --- |
| `AuthorizationResponseException` | Singpass returned an authorization error; `errorCode` contains the provider error code. |
| `InvalidAuthorizationCallbackException` | Callback validation failed, for example because state expired or the issuer did not match. |
| `InvalidIdTokenException` | ID-token decryption or verification failed. |
| `InvalidUserInfoException` | UserInfo decryption, verification, or subject binding failed. |
| `MyinfoV5TransportException` | A connection failed or a retryable response exhausted the allowed attempts. |

Do not continue with personal data after verification fails. For an expired or consumed authorization
transaction, offer the user a new authorization attempt through the POST form above.

### Timeouts and retries

Every request uses the configured connection and overall request timeouts. Safe-read attempts are total
attempts, including the first request, and must be between 1 and 3. The retry delay must be between 0 and
5,000 milliseconds.

| Endpoint | Attempts | Automatically retried failures |
|---|---:|---|
| OpenID discovery | `MYINFO_V5_SAFE_READ_MAX_ATTEMPTS` | Connection failures, `429`, `502`, `503`, `504` |
| Singpass JWKS | `MYINFO_V5_SAFE_READ_MAX_ATTEMPTS` | Connection failures, `429`, `502`, `503`, `504` |
| UserInfo | `MYINFO_V5_SAFE_READ_MAX_ATTEMPTS` | Connection failures, `429`, `502`, `503`, `504`; a fresh DPoP proof and `jti` are generated for every attempt |
| PAR | 1 | Never automatically retried |
| Token exchange | 1 | Never automatically retried |

Connection failures and exhausted retryable responses throw
`Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5\MyinfoV5TransportException`. Its `endpoint()` method returns a
safe endpoint category, and `restartAuthorization()` tells the application how to recover. When
`restartAuthorization()` is `true`, the authorization outcome is ambiguous: discard that attempted flow
and start a new authorization. Never replay its old authorization code, client assertion, or DPoP proof.
When it is `false` and you already have a `VerifiedTokenSet`, you may retry UserInfo using that object
within the current request; the package will generate a new DPoP proof. The token set cannot be serialized
into a session or queued job. Do not retry `completeAuthorization()` with an already consumed callback.

```php
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV5\MyinfoV5TransportException;
use Ziming\LaravelMyinfoSg\Http\Integrations\MyinfoV5\MyinfoConnector;

$myinfoConnector = new MyinfoConnector;

try {
    $tokenSet = $myinfoConnector->completeAuthorization($request);
    $userInfo = $myinfoConnector->getVerifiedUserInfo($tokenSet);
} catch (MyinfoV5TransportException $exception) {
    if ($exception->restartAuthorization()) {
        // Offer a new authorization attempt using the POST form in your UI.
        return response()->json(['message' => 'Please start Singpass authorization again.'], 503);
    }

    return response()->json(['message' => 'Singpass is temporarily unavailable.'], 503);
}
```

Singpass discovery and JWKS responses remain normally cached for one hour. If ID-token or UserInfo
verification finds an unknown signing key or a bad signature, the package invalidates the cached Singpass
JWKS and refreshes it exactly once before returning the existing sanitized invalid-token error. Decryption,
algorithm, nonce, claim, and subject failures do not trigger a JWKS refresh.

## Generate JWKS

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

The private file is created with owner-only permissions (`0600`). Keep it outside the public web root and
store its contents in `MYINFO_V5_PRIVATE_JWKS` or an appropriate secrets manager. The public file contains
matching public keys without the private `d` property and can be used for `MYINFO_V5_PUBLIC_JWKS` or the
public JWKS endpoint. The command also prints the generated `MYINFO_V5_CHOSEN_JWKS_SIG_KID` value.

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
    --signing-kid="$MYINFO_V5_CHOSEN_JWKS_SIG_KID"
```

### Select the DPoP signing profile

`MYINFO_V5_DPOP_SIGNING_ALG` independently selects the algorithm for the ephemeral DPoP key. It does
not select the registered client-assertion key controlled by `MYINFO_V5_CHOSEN_JWKS_SIG_KID`.

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

## Rotate JWKS

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
Singpass JWKS cache, and then change `MYINFO_V5_CHOSEN_JWKS_SIG_KID` to the new `kid` printed by the command.
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

## Development

From the package repository:

```bash
composer install
composer test
composer analyse
```

Use `vendor/bin/testbench` instead of `php artisan` when running the package's JWKS commands directly
from this repository.

Contributions are welcome. See [CONTRIBUTING](CONTRIBUTING.md) for contribution guidelines and
[CHANGELOG](CHANGELOG.md) for release history.
