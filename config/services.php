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
        // Where the AI runs: "anthropic" (Anthropic API, USA) or "bedrock" (AWS Bedrock, e.g. EU).
        'provider' => env('AI_PROVIDER', 'anthropic'),
        'bedrock' => [
            'region' => env('AWS_BEDROCK_REGION', 'eu-central-1'),
            // Bedrock model/inference-profile id, e.g. "eu.anthropic.claude-sonnet-5".
            'model' => env('AWS_BEDROCK_MODEL', ''),
            // Leave empty to use the server's AWS credentials (env vars or instance role).
            'access_key' => env('AWS_BEDROCK_ACCESS_KEY'),
            'secret_key' => env('AWS_BEDROCK_SECRET_KEY'),
        ],
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
    'google_drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET', ''),
    ],

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

    // Mail intake: every household gets an address name.secret@domain; the mails land in one
    // (catch-all) mailbox of the server's mail service and are fetched by "mail:fetch" via IMAP.
    'inbound_mail' => [
        'domain' => env('INBOUND_MAIL_DOMAIN'),
        'host' => env('INBOUND_MAIL_HOST', '127.0.0.1'),
        'port' => (int) env('INBOUND_MAIL_PORT', 993),
        'encryption' => env('INBOUND_MAIL_ENCRYPTION', 'ssl'),
        'validate_cert' => (bool) env('INBOUND_MAIL_VALIDATE_CERT', false),
        'username' => env('INBOUND_MAIL_USERNAME'),
        'password' => env('INBOUND_MAIL_PASSWORD'),
        'daily_limit' => (int) env('INBOUND_MAIL_DAILY_LIMIT', 100),
    ],

    // Trip map: place search, addresses and places nearby (OpenStreetMap services, called from the server).
    'maps' => [
        'nominatim_url' => env('MAPS_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'overpass_url' => env('MAPS_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        // Search as you type (Photon by Komoot) and walking/cycling/driving routes (OSRM of FOSSGIS).
        'photon_url' => env('MAPS_PHOTON_URL', 'https://photon.komoot.io'),
        'routing_url' => env('MAPS_ROUTING_URL', 'https://routing.openstreetmap.de'),
        // Remember the IP addresses of these services (servers with a slow name lookup).
        'remember_dns' => (bool) env('MAPS_REMEMBER_DNS', true),
        // Map tiles in the browser; empty = OpenStreetMap. May contain a key, e.g. from MapTiler.
        'tile_url' => env('MAPS_TILE_URL'),
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

    // GoCardless Bank Account Data (formerly Nordigen): PSD2 account aggregation for banks that
    // dropped FinTS. Sign up for free at bankaccountdata.gocardless.com and put the keys in the
    // environment. Without them the "connect bank" feature stays hidden.
    'gocardless' => [
        'secret_id' => env('GOCARDLESS_SECRET_ID'),
        'secret_key' => env('GOCARDLESS_SECRET_KEY'),
        'base_url' => env('GOCARDLESS_BASE_URL', 'https://bankaccountdata.gocardless.com/api/v2'),
    ],

    // Enable Banking: PSD2 account aggregation (the open successor since GoCardless stopped new
    // signups). Register a free application at enablebanking.com, set the application id and point
    // ENABLEBANKING_PRIVATE_KEY at the RSA private key (file path or the PEM contents). API auth is
    // a self-signed RS256 JWT (kid = application id). Without a key the feature stays hidden.
    'enablebanking' => [
        'application_id' => env('ENABLEBANKING_APPLICATION_ID'),
        'private_key' => env('ENABLEBANKING_PRIVATE_KEY'),
        'base_url' => env('ENABLEBANKING_BASE_URL', 'https://api.enablebanking.com'),
    ],

    // Two-way calendar sync with Google Calendar. Create an OAuth client at
    // console.cloud.google.com (Calendar API), add the redirect URI and put the keys in the
    // environment. Without them the "connect Google Calendar" feature stays hidden.
    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CALENDAR_REDIRECT'),
    ],

    // ffmpeg is used to extract a poster frame from uploaded videos (media columns). If it is not
    // installed on the server, videos simply keep no poster. Install it with: apt-get install -y ffmpeg
    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    ],

];
