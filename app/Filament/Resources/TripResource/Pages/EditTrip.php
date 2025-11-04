<?php

namespace App\Filament\Resources\TripResource\Pages;

use App\Filament\Resources\TripResource;
use App\Services\TelegramNotificationService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;

class EditTrip extends EditRecord
{
    protected static string $resource = TripResource::class;

    protected function afterSave(): void
    {
        $trip = $this->record;
        
        // Сбрасываем кеш
        if ($trip->driver_id) {
            Cache::forget("driver_{$trip->driver_id}_active_trips");
            Cache::forget("driver_{$trip->driver_id}_available_trips");
            Cache::forget("driver_{$trip->driver_id}_total_trips");
        }
        
        Cache::forget("available_trips_count");

        // Проверяем в кеше - отправляли ли уже уведомление для этой заявки
        $notificationKey = "trip_notification_sent_{$trip->id}";
        
        if ($trip->status == 'Новая' && $trip->driver_id && !Cache::has($notificationKey)) {
            \Log::info('Sending notification for trip');
            $notificationService = new TelegramNotificationService();
            $sent = $notificationService->sendNewTripNotification($trip);
            
            if ($sent) {
                // Сохраняем в кеш на 24 часа что уведомление отправлено
                Cache::put($notificationKey, true, 86400); // 24 часа
                \Log::info('Notification sent and cached', ['trip_id' => $trip->id]);
            }
        } else {
            \Log::info('Notification NOT sent', [
                'reason' => $trip->status != 'Новая' ? 'status_not_new' : 
                           (!$trip->driver_id ? 'no_driver' : 'already_sent')
            ]);
        }
    }
}