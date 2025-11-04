<?php

namespace App\Observers;

use App\Models\Trip;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class TripObserver
{
    /**
     * Handle the Trip "created" event.
     */
    public function created(Trip $trip): void
    {
        //
    }

    /**
     * Handle the Trip "updated" event.
     */
    public function updated(Trip $trip): void
    {
        //
    }

    /**
     * Handle the Trip "deleted" event.
     */
    public function deleting(Trip $trip): void
    {
        Log::info('TripObserver: deleting trip', ['trip_id' => $trip->id]);
        
        // Загружаем waybills ДО удаления заявки
        $trip->load('waybills');
        
        Log::info('TripObserver: waybills loaded', [
            'count' => $trip->waybills->count(),
            'waybill_ids' => $trip->waybills->pluck('id')
        ]);
        
        // Удаляем все прикрепленные файлы путевых листов
        foreach ($trip->waybills as $waybill) {
            Log::info('TripObserver: deleting waybill file', [
                'waybill_id' => $waybill->id,
                'file_path' => $waybill->file_path,
                'file_exists' => Storage::disk('public')->exists($waybill->file_path)
            ]);
            
            // Удаляем файл с диска
            if (Storage::disk('public')->exists($waybill->file_path)) {
                $deleted = Storage::disk('public')->delete($waybill->file_path);
                Log::info('TripObserver: file deletion result', ['success' => $deleted]);
            } else {
                Log::warning('TripObserver: file not found', ['file_path' => $waybill->file_path]);
            }
        }
    }

    /**
     * Handle the Trip "restored" event.
     */
    public function restored(Trip $trip): void
    {
        //
    }

    /**
     * Handle the Trip "force deleted" event.
     */
    public function forceDeleted(Trip $trip): void
    {
        //
    }
}