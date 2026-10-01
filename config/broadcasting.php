<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    // Port of the local Reverb process, written into .env by the deploy (the port it starts
    // Reverb with). When set, live updates always go there, whatever else is configured.
    'local_reverb_port' => env('REVERB_LOCAL_PORT'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            // Where the app itself sends live updates. Behind a path-based proxy the public address
            // does not reach Reverb (only the websocket path is forwarded), so the server talks to
            // the local Reverb process directly, e.g. REVERB_PUBLISH_HOST=127.0.0.1, REVERB_PUBLISH_PORT=3021,
            // REVERB_PUBLISH_SCHEME=http. Without these the public address is used as before.
            'options' => [
                'host' => env('REVERB_PUBLISH_HOST', env('REVERB_HOST')),
                'port' => env('REVERB_PUBLISH_PORT', env('REVERB_PORT', 443)),
                'scheme' => env('REVERB_PUBLISH_SCHEME', env('REVERB_SCHEME', 'https')),
                'useTLS' => env('REVERB_PUBLISH_SCHEME', env('REVERB_SCHEME', 'https')) === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
                // Live updates are sent during the request: never let a slow websocket server hold it up.
                'connect_timeout' => 2,
                'timeout' => 3,
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
