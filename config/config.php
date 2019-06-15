<?php

/*
 * You can place your custom package configuration in here.
 */
return [
    'client_id'     => env('MYINFO_APP_CLIENT_ID', 'STG2-MYINFO-SELF-TEST'),
    'client_secret' => env('MYINFO_APP_CLIENT_SECRET', '44d953c796cccebcec9bdc826852857ab412fbe2'),
    'redirect_uri'  => env('MYINFO_APP_REDIRECT_URI', 'http://localhost:3001/callback'),
    'attributes'    => env('MYINFO_APP_ATTRIBUTES', 'uinfin,name,sex,race,nationality,dob,email,mobileno,regadd,housingtype,hdbtype,marital,edulevel,noa-basic,ownerprivate,cpfcontributions,cpfbalances'),
    'purpose'       => env('MYINFO_APP_PURPOSE', 'demonstrating MyInfo APIs'),

    'public_cert_path' => env('MYINFO_SIGNATURE_CERT_PUBLIC_CERT'),
    'private_key_path' => env('MYINFO_APP_SIGNATURE_CERT_PRIVATE_KEY'),

    'auth_level'        => env('MYINFO_AUTH_LEVEL'),
    'api_authorise_uri' => env('MYINFO_API_AUTHORISE'),
    'api_token_uri'     => env('MYINFO_API_TOKEN'),
    'api_person_uri'    => env('MYINFO_API_PERSON'),

    // If this is false, call_authorise_api_uri and get_myinfo_person_data_uri routes would not be registered
    'enable_default_myinfo_routes' => true,

    'call_authorise_api_uri' => env('MYINFO_CALL_AUTHORISE_API_URI', '/redirect-to-singpass'),
    'get_myinfo_person_data_uri' => env('MYINFO_GET_PERSON_DATA_URI', '/myinfo-person'),

];
