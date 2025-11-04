<?php

namespace App\Telegram\Commands;

use App\Models\Trip;
use App\Models\Driver;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use Carbon\Carbon;

class CallbackHandler extends Command
{
    protected string $name = 'callback';
    protected string $pattern = '{callback_data}';

    public function handle()
    {
        $update = $this->getUpdate();
        $callbackQuery = $update->getCallbackQuery();
        $callbackData = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();

        $driver = Driver::where('telegram_chat_id', $chatId)->first();
        
        if (!$driver) {
            $this->replyWithMessage([
                'text' => '❌ Ошибка: водитель не найден'
            ]);
            return;
        }

        // Обрабатываем разные callback_data
        switch ($callbackData) {
            case 'trips_active':
                $trips = $this->getActiveTrips($driver);
                $title = "🟡 Активные заявки";
                break;
                
            case 'trips_today':
                $trips = $this->getTodayTrips($driver);
                $title = "📅 Заявки на сегодня";
                break;
                
            case 'trips_week':
                $trips = $this->getWeekTrips($driver);
                $title = "📆 Заявки за неделю";
                break;
                
            case 'trips_month':
                $trips = $this->getMonthTrips($driver);
                $title = "📅 Заявки за месяц";
                break;
                
            case 'trips_all':
                $trips = $this->getAllTrips($driver);
                $title = "📊 Все заявки";
                break;
                
            default:
                $this->answerCallbackQuery([
                    'text' => 'Неизвестная команда',
                    'show_alert' => false
                ]);
                return;
        }

        $message = $this->formatTripsMessage($title, $trips);
        
        // Обновляем сообщение с новыми данными
        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $this->getTripsKeyboard()
        ]);

        // Подтверждаем callback
        $this->answerCallbackQuery([
            'text' => '✅ Загружено',
            'show_alert' => false
        ]);
    }

    private function getActiveTrips(Driver $driver)
    {
        return Trip::where('driver_id', $driver->id)
            ->where('status', 'В работе')
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get();
    }

    private function getTodayTrips(Driver $driver)
    {
        return Trip::where('driver_id', $driver->id)
            ->whereDate('date', Carbon::today())
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get();
    }

    private function getWeekTrips(Driver $driver)
    {
        return Trip::where('driver_id', $driver->id)
            ->whereBetween('date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get();
    }

    private function getMonthTrips(Driver $driver)
    {
        return Trip::where('driver_id', $driver->id)
            ->whereMonth('date', Carbon::now()->month)
            ->whereYear('date', Carbon::now()->year)
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get();
    }

    private function getAllTrips(Driver $driver)
    {
        return Trip::where('driver_id', $driver->id)
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get();
    }

    private function formatTripsMessage(string $title, $trips): string
    {
        if ($trips->count() === 0) {
            return "{$title}\n\n📭 Заявок не найдено";
        }

        $message = "{$title}\n\n";
        
        foreach ($trips as $trip) {
            $statusEmoji = match($trip->status) {
                'В работе' => '🟡',
                'Выполнена' => '🟢',
                'Отменена' => '🔴',
                'Перенесена' => '⚪',
                default => '⚪'
            };

            $message .= "{$statusEmoji} *{$trip->name}*\n";
            $message .= "📅 " . $trip->date->format('d.m.Y');
            $message .= $trip->time ? " ⏰ " . $trip->time->format('H:i') : "";
            $message .= "\n👤 {$trip->client_name}\n";
            
            if ($trip->amount) {
                $message .= "💵 " . (is_numeric($trip->amount) ? 
                    number_format($trip->amount, 0, '', ' ') . ' руб.' : 
                    $trip->amount) . "\n";
            }
            
            $message .= "🆔 #{$trip->id}\n\n";
        }

        return $message;
    }

    private function getTripsKeyboard()
    {
        return Keyboard::make()
            ->inline()
            ->row([
                Keyboard::inlineButton(['text' => '🟡 Активные', 'callback_data' => 'trips_active']),
                Keyboard::inlineButton(['text' => '📅 Сегодня', 'callback_data' => 'trips_today']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '📆 Неделя', 'callback_data' => 'trips_week']),
                Keyboard::inlineButton(['text' => '📅 Месяц', 'callback_data' => 'trips_month']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '📊 Все', 'callback_data' => 'trips_all']),
                Keyboard::inlineButton(['text' => '🔄 Обновить', 'callback_data' => 'trips_refresh']),
            ]);
    }
}