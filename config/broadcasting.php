<?php

/*
|--------------------------------------------------------------------------
| Broadcasting — Pusher (decision D1)
|--------------------------------------------------------------------------
|
| Pusher is the real-time transport for this platform, not Reverb. This
| file is published (rather than relying on the framework default) so
| Reverb — and every other broadcaster this project doesn't use — isn't
| even a connection option here. `log`/`null` are kept only as safe local
| fallbacks when Pusher isn't configured (see resources/js/echo.js, which
| degrades to polling when VITE_PUSHER_APP_KEY is absent).
|
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

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
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
