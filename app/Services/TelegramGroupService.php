<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TelegramGroupService
{
    private string $botToken;
    private string $chatId;
    
    public function __construct()
    {
        $config = config('telegram-notification.notification_bot');
        $this->botToken = $config['token'] ?? '';
        $this->chatId = $config['group_chat_id'] ?? '';
    }
    
    public function isConfigured(): bool
    {
        return !empty($this->botToken) && !empty($this->chatId);
    }
    
    private function send(string $message, bool $silent = false): bool
    {
        if (!$this->isConfigured()) {
            Log::debug('TelegramGroupService не настроен');
            return false;
        }
        
        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                    'chat_id' => $this->chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'disable_notification' => $silent,
                ]);
            
            if ($response->failed()) {
                Log::error('TelegramGroupService ошибка:', $response->json());
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('TelegramGroupService исключение: ' . $e->getMessage());
            return false;
        }
    }
    
    // 1. УВЕДОМЛЕНИЕ: Водитель принял заявку (через бота)
    public function notifyDriverAcceptedTrip($trip, $driver): bool
    {
        $message = "✅ <b>Водитель {$driver->name} принял заявку #{$trip->id}</b>\n\n";
        
        $message .= "📍 <b>Дата:</b> " . $trip->date . "\n";
        $message .= "📍 <b>Информация:</b> " . $trip->type_t . " " . $trip->car_number . "\n";
        
        $message .= "\n🕐 <i>Принято через бота:</i> " . Carbon::now('Europe/Moscow')->format('H:i:s');
        
        return $this->send($message);
    }
    
    // 2. УВЕДОМЛЕНИЕ: Водитель прикрепил путевой лист
    public function notifyWaybillAttached($trip, $driver, $documentInfo = null): bool
    {
        $message = "📎 <b>Водитель {$driver->name} прикрепил путевой лист</b>\n\n";
        $message .= "📋 <b>Заявка:</b> #{$trip->id}\n";
        $message .= "📍 <b>Информация:</b> " . $trip->type_t . " " . $trip->car_number . "\n";

        $message .= "\n🕐 <i>Прикреплено через бота:</i> " . Carbon::now('Europe/Moscow')->format('H:i:s');
        
        return $this->send($message);
    }
    
    // 3. УВЕДОМЛЕНИЕ: Заявка отменена
    public function notifyTripCancelled($trip, $canceller = null, $reason = null): bool
    {
        $message = "🚫 <b>Заявка #{$trip->id} отменена</b>\n\n";

        if ($trip->driver) {
            $message .= "👤 <b>Водитель:</b> {$trip->driver->name}\n";
        }
        
        $message .= "📍 <b>Информация:</b> " . $trip->type_t . " " . $trip->car_number . "\n";
        
        $message .= "\n🕐 <i>Отменена:</i> " . Carbon::now('Europe/Moscow')->format('H:i:s');
        
        return $this->send($message);
    }
    
    // 4. УВЕДОМЛЕНИЕ: Заявка переведена в ремонт
    public function notifyTripToRepair($trip, $user = null, $reason = null): bool
    {
        $message = "🔧 <b>Заявка #{$trip->id} В РЕМОНТЕ</b>\n\n";

        if ($trip->driver) {
            $message .= "👤 <b>Водитель:</b> {$trip->driver->name}\n";
        }

        $message .= "📍 <b>Информация:</b> " . $trip->type_t . " " . $trip->car_number . "\n";
        
        $message .= "\n🕐 <i>Статус изменен:</i> " . Carbon::now('Europe/Moscow')->format('H:i:s');
        
        return $this->send($message);
    }
    
    // Тестовое сообщение
    public function sendTest(): bool
    {
        $message = "🤖 <b>Тест системы уведомлений</b>\n\n";
        $message .= "Бот для групповых уведомлений работает!\n";
        $message .= "Этот бот только отправляет сообщения\n";
        $message .= "и не отвечает на команды в чате.\n\n";
        $message .= "✅ Система готова к работе\n";
        $message .= "🕐 " . now()->format('d.m.Y H:i:s');
        
        return $this->send($message);
    }
}