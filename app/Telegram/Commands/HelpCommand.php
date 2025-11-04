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
            'text' => '🆘 Ваш запрос передан диспетчеру. Ожидайте ответа в ближайшее время.' . "\n\n" .
                     'Диспечер свяжется с вами для уточнения деталей.'
        ]);
        
        // TODO: Здесь добавим уведомление для диспетчера в админку
    }
}