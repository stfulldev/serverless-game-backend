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

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'aws' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'dynamodb_endpoint' => env('DYNAMODB_ENDPOINT'),
        'dynamodb_tables' => [
            'players' => env('DYNAMODB_PLAYERS_TABLE'),
            'wallets' => env('DYNAMODB_WALLETS_TABLE'),
            'buildings' => env('DYNAMODB_BUILDINGS_TABLE'),
            'productions' => env('DYNAMODB_PRODUCTIONS_TABLE'),
            'occupied_cells' => env('DYNAMODB_OCCUPIED_CELLS_TABLE'),
            'cleared_obstacles' => env('DYNAMODB_CLEARED_OBSTACLES_TABLE'),
            'commands' => env('DYNAMODB_COMMANDS_TABLE'),
            'outbox_events' => env('DYNAMODB_OUTBOX_EVENTS_TABLE'),
        ],
    ],

    'cognito' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'user_pool_id' => env('COGNITO_USER_POOL_ID'),
        'client_id' => env('COGNITO_CLIENT_ID'),
        'issuer' => env('COGNITO_ISSUER'),
        'jwks_cache_ttl' => env('COGNITO_JWKS_CACHE_TTL', 21600),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
