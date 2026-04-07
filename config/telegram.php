<?php

return [
    'bots' => [
        'solyto' => [
            'class' => \App\Bots\SolytoBot::class,
            'webhook_token' => \App\Shared\Helpers\DockerSecretHelper::get('SOLYTO_BOT_WEBHOOK_TOKEN'),
            'telegram_token' => \App\Shared\Helpers\DockerSecretHelper::get('SOLYTO_BOT_TELEGRAM_TOKEN'),
        ],
    ]
];
