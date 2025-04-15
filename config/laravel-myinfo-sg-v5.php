<?php

declare(strict_types=1);

return [

    // Their side
    'issuer_uri' => env('MYINFO_V5_ISSUER_URI', 'https://stg-id.singpass.gov.sg'),

    // Our side

    // the client id here is from myinfo demo app
    'client_id'     => env('MYINFO_V5_CLIENT_ID', 'RsrOy2iB0edR53TJSuD5ULad1pGmrVZL'),
    'redirect_uri'  => env('MYINFO_V5_REDIRECT_URI', 'http://localhost:3080/callback'),
    'scope'    => env('MYINFO_V5_ATTRIBUTES', 'openid uinfin name mobileno'),
    'scope_array' => explode(' ', env('MYINFO_V5_ATTRIBUTES', 'openid uinfin name mobileno')),

    'public_jwks' => env('MYINFO_V5_PUBLIC_JWKS'),
    'private_jwks' => env('MYINFO_V5_PRIVATE_JWKS'),
    'chosen_jwks_sig_kid' => env('MYINFO_V5_CHOSEN_JWKS_SIG_KID'),
    'chosen_jwks_enc_kid' => env('MYINFO_V5_CHOSEN_JWKS_ENC_KID'),

    'enable_default_myinfo_authorization_redirect_route' => false,
    'call_authorization_api_uri' => env('MYINFO_V5_CALL_AUTHORISE_API_URL', '/redirect-to-singpass-myinfo-v5'),
    'call_authorization_api_controller' => \Ziming\LaravelMyinfoSg\Http\Controllers\CallV5AuthorizeApiController::class,
    'state_session_name' => env('MYINFO_V5_STATE_SESSION_NAME', 'state'),
    'code_verifier_session_name' => env('MYINFO_V5_CODE_VERIFIER_SESSION_NAME', 'code_verifier'),

    'enable_default_public_jwks_endpoint_route' => false,
    'public_jwks_uri' => env('MYINFO_V5_PUBLIC_JWKS_URI', '/.well-known/public-singpass-jwks.json'),
    'public_jwks_controller' => \Ziming\LaravelMyinfoSg\Http\Controllers\PublicJwksEndpointController::class,

    'debug_mode' => env('MYINFO_V5_DEBUG_MODE', false),

];
