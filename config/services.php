<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Claude API for the recipe import (URL, photo, PDF, text) and nutrition estimates.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        // Comma-separated AI functions that are switched off (see App\Support\AiFeatures).
        'disabled_features' => env('AI_DISABLED_FEATURES', ''),
    ],

    // Web push (generate the keys with "php artisan webpush:vapid").
    // Market data for the portfolio manager (Yahoo Finance needs no key).
    'twelve_data' => [
        'key' => env('TWELVE_DATA_KEY'),
    ],

    'openfigi' => [
        'key' => env('OPENFIGI_KEY'),
    ],

    // FinTS/HBCI online banking: product ID registered at https://www.hbci-zka.de (Registrierung).
    'fints' => [
        'product_id' => env('FINTS_PRODUCT_ID', ''),
    ],

    // Instagram analysis: posts are fetched with Apify's Instagram scraper and read by the AI.
    'instagram' => [
        'apify_key' => env('APIFY_API_KEY'),
        'profile' => env('INSTAGRAM_PROFILE', 'heikothieme.club'),
        'limit' => (int) env('INSTAGRAM_LIMIT', 20),
        // Shared secret for pushing analysed videos from another machine (e.g. a Raspberry Pi).
        'push_key' => env('INSTAGRAM_PUSH_KEY'),
    ],

    // RezepteApp2 photos for the recipe import (read only, nothing is changed there).
    'rezepteapp' => [
        'uploads_path' => env('REZEPTEAPP_UPLOADS_PATH', '/var/www/vhosts/zimmimail.de/httpdocs/RezepteApp2/uploads'),
        'uploads_url' => env('REZEPTEAPP_UPLOADS_URL', 'https://zimmimail.de/RezepteApp2/uploads'),
    ],

    'pushover' => [
        'token' => env('PUSHOVER_APP_TOKEN'),
    ],

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Current supermarket offers for the offers page (marktguru.de; keys are read from its
    // website when left empty).
    'offers' => [
        'provider' => env('OFFERS_PROVIDER', 'marktguru'),
        'api_key' => env('OFFERS_API_KEY'),
        'client_key' => env('OFFERS_CLIENT_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
