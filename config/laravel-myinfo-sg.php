<?php

use Ziming\LaravelMyinfoSg\Http\Controllers\CallAuthoriseApiController;
use Ziming\LaravelMyinfoSg\Http\Controllers\GetMyinfoPersonDataController;

return [
    'client_id' => env('MYINFO_APP_CLIENT_ID', 'STG2-MYINFO-SELF-TEST'),
    'redirect_url' => env('MYINFO_APP_REDIRECT_URL', 'http://localhost:3001/callback'),
    'scope' => env('MYINFO_APP_ATTRIBUTES', 'uinfin name sex race nationality dob email mobileno regadd housingtype hdbtype marital noa-basic ownerprivate cpfcontributions cpfbalances'),
    'scope_array' => explode(',', env('MYINFO_APP_ATTRIBUTES', 'uinfin name sex race nationality dob email mobileno regadd housingtype hdbtype marital noa-basic ownerprivate cpfcontributions cpfbalances')),
    'purpose' => env('MYINFO_APP_PURPOSE', 'demonstrating MyInfo APIs'),

    'client_assertion_private_signing_key_path' => env('MYINFO_CLIENT_ASSERTION_PRIVATE_KEY_PATH'),

    // folder to private encryption keys, allow multiple keys to match multiple encryption keys in JWKS
    'private_encryption_keys_folder_path' => env('MYINFO_PRIVATE_ENCRYPTION_KEYS_FOLDER_PATH'),

    'api_token_url' => env('MYINFO_API_TOKEN', 'https://test.api.myinfo.gov.sg/com/v4/token'),
    'api_person_url' => env('MYINFO_API_PERSON', 'https://test.api.myinfo.gov.sg/com/v4/person'),

    'api_authorise_jwks_url' => env('MYINFO_API_AUTHORISE_JWKS_URL', 'https://test.authorise.singpass.gov.sg/.well-known/keys.json'),
    'api_myinfo_jwks_url' => env('MYINFO_API_MYINFO_JWKS_URL', 'https://test.myinfo.singpass.gov.sg/.well-known/keys.json'),

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
