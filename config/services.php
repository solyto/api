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

    'solyto_ai' => [
        'api_url' => env('SOLYTO_AI_URL'),
        'endpoints' => [
            'recommend_music' => 'libraries/music/recommend',
            'music_releases' => 'libraries/music/releases',
            'recommend_books' => 'libraries/books/recommend',
            'books_releases' => 'libraries/books/releases',
            'chat_with_assistant' => 'assistants/chat',
        ]
    ],

    'telegram' => [
        'api' => [
            'base_url' => 'https://api.telegram.org/bot%s/'
        ]
    ],

    'bremerstadtreinigung' => [
        'trash_calendar_url' => 'https://www.die-bremer-stadtreinigung.de/api/c-trace/app/garbage-file-ics?street=%s&houseNo=%s',
    ],

    'hardcover' => [
        'api_key' => \App\Shared\Helpers\DockerSecretHelper::get('HARDCOVER_API_KEY'),
    ],

    'tmdb' => [
        'access_token' => \App\Shared\Helpers\DockerSecretHelper::get('TMDB_ACCESS_TOKEN'),
    ],

    'ai' => [
        'api_key' => \App\Shared\Helpers\DockerSecretHelper::get('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL'),
        'model' => env('AI_MODEL', 'meta-llama/Llama-3.3-70B-Instruct'),
    ],

    'radicale' => [
        'url' => env('RADICALE_URL'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => \App\Shared\Helpers\DockerSecretHelper::get('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.eu.mailgun.net'),
        'scheme' => 'https',
    ],

    'bgg' => [
        'api_key' => \App\Shared\Helpers\DockerSecretHelper::get('BGG_API_KEY'),
    ],

    'imgproxy' => [
        'url'  => env('IMGPROXY_URL', 'http://imgproxy:8080'),
        'key'  => \App\Shared\Helpers\DockerSecretHelper::get('IMGPROXY_KEY'),
        'salt' => \App\Shared\Helpers\DockerSecretHelper::get('IMGPROXY_SALT'),
    ],

    'image' => [
        'driver' => env('IMAGE_DRIVER', 'intervention'),
    ],
];
