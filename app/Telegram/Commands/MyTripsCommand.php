<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use App\Models\Driver;
use App\Models\Trip;
use Telegram\Bot\Laravel\Facades\Telegram;

class MyTripsCommand extends Command
{
    protected string $name = 'mytrips';
    protected string $description = 'Показать мои заявки';

    public function handle()
    {
        $update = $this->getUpdate();
        $chatId = $update->getMessage()->getChat()->getId();
        
        // Ищем водителя
        $driver = Driver::where('telegram_chat_id', $chatId)->first();
        
        if (!$driver) {
            $this->replyWithMessage([
                'text' => '❌ Водитель не найден. Используйте /start для регистрации.'
            ]);
            return;
        }

        // Используем новый метод из контроллера
        $telegramController = new \App\Http\Controllers\TelegramController();
        $telegramController->showMainMenu($driver, $chatId);
    }
}