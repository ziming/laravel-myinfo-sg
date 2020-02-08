<?php

use Ziming\LaravelMyinfoSg\Http\Controllers\CallAuthoriseApiController;
use Ziming\LaravelMyinfoSg\Http\Controllers\GetMyinfoPersonDataController;

return [
    'client_id'     => env('MYINFO_APP_CLIENT_ID', 'STG2-MYINFO-SELF-TEST'),
    'client_secret' => env('MYINFO_APP_CLIENT_SECRET', '44d953c796cccebcec9bdc826852857ab412fbe2'),
    'redirect_url'  => env('MYINFO_APP_REDIRECT_URL', 'http://localhost:3001/callback'),
    'attributes'    => env('MYINFO_APP_ATTRIBUTES', 'uinfin,name,sex,race,nationality,dob,email,mobileno,regadd,housingtype,hdbtype,marital,edulevel,noa-basic,ownerprivate,cpfcontributions,cpfbalances'),
    'attributes_array' => explode(',', env('MYINFO_APP_ATTRIBUTES', 'uinfin,name,sex,race,nationality,dob,email,mobileno,regadd,housingtype,hdbtype,marital,edulevel,noa-basic,ownerprivate,cpfcontributions,cpfbalances')),
    'purpose'       => env('MYINFO_APP_PURPOSE', 'demonstrating MyInfo APIs'),

    'public_cert_path' => env('MYINFO_SIGNATURE_CERT_PUBLIC_CERT'),
    'private_key_path' => env('MYINFO_APP_SIGNATURE_CERT_PRIVATE_KEY'),

    'auth_level'        => env('MYINFO_AUTH_LEVEL'),
    'api_authorise_url' => env('MYINFO_API_AUTHORISE'),
    'api_token_url'     => env('MYINFO_API_TOKEN'),
    'api_person_url'    => env('MYINFO_API_PERSON'),

    // If this is false, call_authorise_api_url and get_myinfo_person_data_url routes would not be registered
    'enable_default_myinfo_routes' => true,

    'call_authorise_api_url' => env('MYINFO_CALL_AUTHORISE_API_URL', '/redirect-to-singpass'),
    'get_myinfo_person_data_url' => env('MYINFO_GET_PERSON_DATA_URL', '/myinfo-person'),

    // The default controllers used my the default provided myinfo routes.
    'call_authorise_api_controller' => CallAuthoriseApiController::class,
    'get_myinfo_person_data_controller' => GetMyinfoPersonDataController::class,

    // Debug mode
    'debug_mode' => env('MYINFO_DEBUG_MODE', false),
];
