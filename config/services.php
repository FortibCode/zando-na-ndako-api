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

    // Client(s) OAuth Google (console.cloud.google.com > APIs & Services > Identifiants) — un id_token
    // reçu du web ou du mobile peut porter n'importe lequel de ces "aud" selon la plateforme d'origine,
    // d'où la liste plutôt qu'un identifiant unique. Vide tant que le projet Google Cloud n'existe pas.
    'google' => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))))),
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

    'airtel_money' => [
        'environment'   => env('AIRTEL_MONEY_ENVIRONMENT', 'sandbox'),
        'country'       => env('AIRTEL_MONEY_COUNTRY', 'CG'),
        'currency'      => env('AIRTEL_MONEY_CURRENCY', 'XAF'),
        'client_id'     => env('AIRTEL_MONEY_CLIENT_ID'),
        'client_secret' => env('AIRTEL_MONEY_CLIENT_SECRET'),
    ],

];
