<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;

class HelpCommand extends Command
{
    protected string $name = 'help';
    protected string $description = 'Contact dispatcher';

    public function handle()
    {
        $this->replyWithMessage([
            'text' => '🆘 Пожалуйста, свяжитесь с диспетчером.' . "\n\n" .
                     ' '
        ]);
        
        // TODO: Здесь добавим уведомление для диспетчера в админку
    }
}