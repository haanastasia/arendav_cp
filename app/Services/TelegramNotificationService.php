<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\Driver;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    /**
     * Отправка уведомления о новой заявке
     */
    public function sendNewTripNotification(Trip $trip)
    {

        \Log::info('TelegramNotificationService called', [
            'trip_id' => $trip->id,
            'driver_id' => $trip->driver_id
        ]);

        // Проверяем, что заявка новая и есть водитель
        if ($trip->status !== 'Новая' || !$trip->driver_id) {
            return false;
        }

        $driver = Driver::find($trip->driver_id);
        
        // Проверяем, что у водителя есть Telegram и он зарегистрирован
        if (!$driver || !$driver->telegram_chat_id) {
            return false;
        }

        try {
            $text = "🚗 📋 НОВАЯ ЗАЯВКА!\n\n";
            $text .= "🆔 #{$trip->id}\n";
            $text .= "📍 Адрес подачи: {$trip->address} \n";
            $text .= "👤 Клиент: {$trip->client_name} \n";
            
            if ($trip->load_date) {
                $text .= "📅 Дата создания: " . \Carbon\Carbon::parse($trip->load_date)->format('d.m.Y H:i') . "\n";
            }
            
            if ($trip->date) {
                $text .= "📅 Дата: " . \Carbon\Carbon::parse($trip->date)->format('d.m.Y') . "\n";
            }
            
            // Если есть время, добавляем его
            if ($trip->time) {
                $text .= "⏰ Время: {$trip->time}\n";
            }
            
            $text .= "\n💡 Заявка ожидает вашего подтверждения!";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Принять заявку', 'callback_data' => 'trip_take_' . $trip->id],
                        ['text' => '👀 Подробнее', 'callback_data' => 'trip_details_' . $trip->id],
                    ]
                ]
            ];

            Telegram::sendMessage([
                'chat_id' => $driver->telegram_chat_id,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);

            Log::info('New trip notification sent', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'driver_name' => $driver->name
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send Telegram notification', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}