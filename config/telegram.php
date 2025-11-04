<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    
    'commands' => [
        App\Telegram\Commands\StartCommand::class,
        App\Telegram\Commands\HelpCommand::class,    
        App\Telegram\Commands\RegisterCommand::class,
        App\Telegram\Commands\MyTripsCommand::class,
        //App\Telegram\Commands\CallbackHandler::class,
    ],
];