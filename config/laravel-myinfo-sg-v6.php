<?php

declare(strict_types=1);

return [

    // Their side
    'issuer_uri' => env('MYINFO_V6_ISSUER_URI', 'https://stg-id.singpass.gov.sg'),

    // Our side
    'client_id'     => env('MYINFO_V6_CLIENT_ID'),
    'redirect_uri'  => env('MYINFO_V6_REDIRECT_URI'),
    'scopes'    => env('MYINFO_V6_SCOPES', 'openid'),
    'scopes_array' => explode(' ', env('MYINFO_V6_SCOPES', 'openid')),
    'public_jwks' => env('MYINFO_V6_PUBLIC_JWKS'),
    'private_jwks' => env('MYINFO_V6_PRIVATE_JWKS'),
    'chosen_jwks_sig_kid' => env('MYINFO_V6_CHOSEN_JWKS_SIG_KID'),
    'dpop_signing_alg' => env('MYINFO_V6_DPOP_SIGNING_ALG', 'ES256'),

    // Outbound transport boundaries
    'connect_timeout_seconds' => \Illuminate\Support\Env::get('MYINFO_V6_CONNECT_TIMEOUT_SECONDS', 5),
    'request_timeout_seconds' => \Illuminate\Support\Env::get('MYINFO_V6_REQUEST_TIMEOUT_SECONDS', 15),
    'safe_read_max_attempts' => \Illuminate\Support\Env::get('MYINFO_V6_SAFE_READ_MAX_ATTEMPTS', 2),
    'safe_read_retry_delay_milliseconds' => \Illuminate\Support\Env::get('MYINFO_V6_SAFE_READ_RETRY_DELAY_MILLISECONDS', 200),

    'state_session_key' => env('MYINFO_V6_STATE_SESSION_KEY', 'myinfo_v6_state'),
    'nonce_session_key' => env('MYINFO_V6_NONCE_SESSION_KEY', 'myinfo_v6_nonce'),
    'code_verifier_session_key' => env('MYINFO_V6_CODE_VERIFIER_SESSION_KEY', 'myinfo_v6_code_verifier'),
    'redirect_uri_session_key' => env('MYINFO_V6_REDIRECT_URI_SESSION_KEY', 'myinfo_v6_redirect_uri'),
    'dpop_private_jwk_session_key' => env('MYINFO_V6_DPOP_PRIVATE_JWK_SESSION_KEY', 'myinfo_v6_dpop_private_jwk'),

    'transaction_session_key' => 'myinfo_v6_transactions',
    'transaction_ttl_seconds' => 600,

    'enable_default_myinfo_authorization_redirect_route' => env('MYINFO_V6_ENABLE_DEFAULT_AUTHORIZATION_REDIRECT_ROUTE', false),
    'call_authorization_api_uri' => env('MYINFO_V6_CALL_AUTHORIZATION_API_URI', '/redirect-to-singpass-v6'),
    'call_authorization_api_controller' => env('MYINFO_V6_CALL_AUTHORIZATION_API_CONTROLLER', \Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\CallAuthorizationApiController::class),

    'enable_default_public_jwks_endpoint_route' => env('MYINFO_V6_ENABLE_DEFAULT_PUBLIC_JWKS_ENDPOINT_ROUTE', false),
    'public_jwks_uri' => env('MYINFO_V6_PUBLIC_JWKS_URI', '/sp/v6/jwks'),
    'public_jwks_controller' => env('MYINFO_V6_PUBLIC_JWKS_CONTROLLER', \Ziming\LaravelMyinfoSg\Http\Controllers\MyinfoV6\PublicJwksController::class),

    'debug_mode' => env('MYINFO_V6_DEBUG_MODE', false),

];
