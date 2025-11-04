<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'Start command to get started';

    public function handle()
    {
        $this->replyWithMessage([
            'text' => '👋 Добро пожаловать в Arendav Dispatcher!' . "\n\n" .
                     'Я помогу вам связаться с диспетчером и отправлять путевые листы.' . "\n\n" .
                     '📋 Доступные команды:' . "\n" .
                     '/help - Связаться с диспетчером' . "\n" .
                     '/waybill - Отправить путевой лист' . "\n" .
                     '/status - Статусы заявок'
        ]);
    }
}