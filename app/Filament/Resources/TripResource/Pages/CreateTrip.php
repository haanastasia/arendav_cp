<?php

namespace App\Filament\Resources\TripResource\Pages;

use App\Filament\Resources\TripResource;
use App\Services\TelegramNotificationService;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Auth\Access\AuthorizationException;

class CreateTrip extends CreateRecord
{
    protected static string $resource = TripResource::class;

    protected function authorizeAccess(): void
    {
        if (!auth()->user()->canEdit()) {
            throw new AuthorizationException('У вас нет прав для создания заявок');
        }
    }

    protected function afterCreate(): void
    {
        $trip = $this->record;
        
        // Сбрасываем кеш
        Cache::forget("available_trips_count");
        
        if ($trip->driver_id) {
            Cache::forget("driver_{$trip->driver_id}_active_trips");
            Cache::forget("driver_{$trip->driver_id}_available_trips");
            Cache::forget("driver_{$trip->driver_id}_total_trips");
        }
        
        // Отправляем уведомление
        if ($trip->status == 'Новая' && $trip->driver_id) {
            $notificationService = new TelegramNotificationService();
            $notificationService->sendNewTripNotification($trip);
        }
    }
}