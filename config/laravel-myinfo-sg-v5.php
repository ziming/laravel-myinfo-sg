<?php

declare(strict_types=1);

return [

    // Their side
    'issuer_uri' => env('MYINFO_V5_ISSUER_URI', 'https://stg-id.singpass.gov.sg'),

    // Our side

    // the client id here is from myinfo demo app
    'client_id'     => env('MYINFO_V5_CLIENT_ID', 'RsrOy2iB0edR53TJSuD5ULad1pGmrVZL'),
    'redirect_uri'  => env('MYINFO_V5_REDIRECT_URI', 'http://localhost:3080/callback'),
    'scopes'    => env('MYINFO_V5_SCOPES', 'openid uinfin name mobileno'),
    'scopes_array' => explode(' ', env('MYINFO_V5_SCOPES', 'openid uinfin name mobileno')),
    'public_jwks' => env('MYINFO_V5_PUBLIC_JWKS'),
    'private_jwks' => env('MYINFO_V5_PRIVATE_JWKS'),
    'chosen_jwks_sig_kid' => env('MYINFO_V5_CHOSEN_JWKS_SIG_KID'),

    'state_session_key' => env('MYINFO_V5_STATE_SESSION_KEY', 'state'),
    'code_verifier_session_key' => env('MYINFO_V5_CODE_VERIFIER_SESSION_KEY', 'code_verifier'),

    'debug_mode' => env('MYINFO_V5_DEBUG_MODE', false),

];
