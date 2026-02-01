<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use App\Models\Driver;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'Начало работы с ботом';

    public function handle()
    {
        $update = $this->getUpdate();
        $chatId = $update->getMessage()->getChat()->getId();
        
        $driver = Driver::where('telegram_chat_id', $chatId)->first();

        if ($driver) {
            $text = "✅ Вы зарегистрированы как: {$driver->name}\n\n";
            $text .= "🚗 Используйте /mytrips для работы с заявками\n";
            //$text .= "📞 /help - связь с диспетчером\n";
            $text .= "📄 Отправляйте путевые листы через меню заявок";
        } else {
            $text = "👋 Добро пожаловать!\n\n";
            $text .= "Для работы с ботом нужна регистрация в системе.\n";
           //$text .= "Обратитесь к диспетчеру: /help";
        }

        $this->replyWithMessage([
            'text' => $text
        ]);
    }
}