<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'twilio' => [
        'sid'   => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from'  => env('TWILIO_FROM'),
    ],

    'openrouteservice' => [
        'key' => env('ORS_API_KEY'),
    ],

    'stripe' => [
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret'    => env('PAYPAL_SECRET'),
        'mode'      => env('PAYPAL_MODE', 'sandbox'), // 'sandbox' ou 'live'
    ],

    'mtn_momo' => [
        'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'),
        'currency'    => env('MTN_MOMO_CURRENCY', 'XAF'),
        'collection'  => [
            'user_id'          => env('MTN_MOMO_COLLECTION_USER_ID'),
            'api_key'          => env('MTN_MOMO_COLLECTION_API_KEY'),
            'subscription_key' => env('MTN_MOMO_COLLECTION_SUBSCRIPTION_KEY'),
        ],
        'disbursement' => [
            'user_id'          => env('MTN_MOMO_DISBURSEMENT_USER_ID'),
            'api_key'          => env('MTN_MOMO_DISBURSEMENT_API_KEY'),
            'subscription_key' => env('MTN_MOMO_DISBURSEMENT_SUBSCRIPTION_KEY'),
        ],
    ],

];
