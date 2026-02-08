<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\TelegramNotificationService;
use App\Services\TelegramGroupService;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'date',
        'time', 
        'dispatcher_id',
        'client_name',
        'client_type',
        'status',
        'driver_id',
        'amount',
        'payment_type',
        'address',
        'notes',
        'height', 
        'car_number',
        'type_t',
        'actual_amount',
        'tech_amount',
        'dispatcher_percent',
        'vat',
        'total',
        'usn',
        'work_time',
        'km_check',
        'invoice',
        'paid_status', 
        'tech_payment',
        'reason',
        'hours_dispatcher',
        'hours_driver', 
        'km_dispatcher',
        'km_driver',    
        'document',
        'comment',
        'telegram_sent',
        'telegram_sent_at',
        'telegram_sent_count',
        'client_inn'
    ];

    protected $casts = [
        'document'         => 'array',
        'telegram_sent'    => 'boolean',
        'telegram_sent_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updated(function ($trip) {
            // Пропускаем если обновляются только технические поля
            $technicalFields = ['telegram_sent', 'telegram_sent_at', 'telegram_sent_count'];
            $dirtyFields = array_keys($trip->getDirty());
            
            // Если изменились только технические поля - не обрабатываем
            if (count(array_diff($dirtyFields, $technicalFields)) === 0) {
                return;
            }
            
            // 1. Останавливаем напоминания если статус изменился
            if ($trip->isDirty('status')) {
                $originalStatus = $trip->getOriginal('status');
                $newStatus = $trip->status;
                
                \Log::info('Trip status changed', [
                    'trip_id' => $trip->id,
                    'from' => $originalStatus,
                    'to' => $newStatus
                ]);
                
                // Если был статус "Новая", а стал любой другой - останавливаем напоминания
                if ($originalStatus === 'Новая' && $newStatus !== 'Новая') {
                    try {
                        app(\App\Services\TelegramNotificationService::class)
                            ->stopRemindersForTrip($trip);
                        \Log::info('Reminders stopped due to status change', [
                            'trip_id' => $trip->id
                        ]);
                    } catch (\Exception $e) {
                        \Log::error('Error stopping reminders: ' . $e->getMessage());
                    }
                }
            }
            
            // 2. Автоматически отправляем уведомление об отмене
            // Только если статус изменился НА "Отменена"
            if ($trip->isDirty('status') && $trip->status === 'Отменена') {
                try {
                    app(\App\Services\TelegramNotificationService::class)
                        ->sendCancellationNotification($trip);
                } catch (\Exception $e) {
                    \Log::error('Error sending cancellation notification: ' . $e->getMessage());
                }

                // Уведомление в группу
                try {
                    $groupService = app(\App\Services\TelegramGroupService::class);
                    $success = $groupService->notifyTripCancelled($trip);
                } catch (\Exception $e) {
                    \Log::error('Error sending group cancellation notification: ' . $e->getMessage());
                }

            }

            // статус "Ремонт" 
            if ($trip->isDirty('status') && $trip->status === 'Ремонт') {
                try {
                    // Уведомление в группу о ремонте
                    app(\App\Services\TelegramGroupService::class)->notifyTripToRepair($trip);
                } catch (\Exception $e) {
                    \Log::error('Error sending repair notification: ' . $e->getMessage());
                }
            }
            
            // 3. Смена водителя
            if ($trip->isDirty('driver_id')) {
                // Останавливаем напоминания для старого водителя
                try {
                    app(\App\Services\TelegramNotificationService::class)
                        ->stopRemindersForTrip($trip);
                } catch (\Exception $e) {
                    \Log::error('Error stopping reminders: ' . $e->getMessage());
                }
            }
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function waybills()
    {
        return $this->hasMany(Waybill::class);
    }

    public function dispatcher()
    {
        return $this->belongsTo(User::class, 'dispatcher_id');
    }

    // Метод для проверки
    public function getHasTelegramSentAttribute(): bool
    {
        return $this->telegram_sent;
    }

    // Метод для отметки отправки
    public function markTelegramSent(): self
    {
        $this->update([
            'telegram_sent' => true,
            'telegram_sent_at' => now(),
            'telegram_sent_count' => $this->telegram_sent_count + 1,
        ]);
        
        return $this;
    }

    // Для получения первого документа 
    public function getFirstDocumentAttribute()
    {
        return !empty($this->document) ? $this->document[0] : null;
    }

    // Для получения количества документов
    public function getDocumentsCountAttribute()
    {
        return !empty($this->document) ? count($this->document) : 0;
    }

    // Для проверки наличия документов
    public function hasDocuments(): bool
    {
        return !empty($this->document) && count($this->document) > 0;
    }
}