<?php

return [
    // Новый бот для уведомлений в группу
    'notification_bot' => [
        'token' => env('TELEGRAM_NOTIFICATION_BOT_TOKEN'),
        'username' => env('TELEGRAM_NOTIFICATION_BOT_USERNAME'),
        'group_chat_id' => env('TELEGRAM_GROUP_CHAT_ID'),
    ],
];