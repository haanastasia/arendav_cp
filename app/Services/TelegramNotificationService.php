<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\Driver;
use App\Models\TripReminder;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TelegramNotificationService
{
    /**
     * ГЛАВНЫЙ МЕТОД: Отправка информации водителю (вызывается по кнопке)
     */
    public function sendDriverNotification(Trip $trip): bool
    {
        \Log::info('Sending driver notification', [
            'trip_id' => $trip->id,
            'driver_id' => $trip->driver_id,
            'status' => $trip->status
        ]);

        // Проверяем что есть водитель
        if (!$trip->driver_id) {
            return false;
        }

        $driver = Driver::find($trip->driver_id);
        
        // Проверяем, что у водителя есть Telegram
        if (!$driver || !$driver->telegram_chat_id) {
            return false;
        }

        try {
            // ВАЖНО: Разная логика для статуса "Новая" и других статусов
            if ($trip->status === 'Новая') {
                // Для НОВОЙ заявки - отправляем как первое уведомление и создаем напоминания
                $result = $this->sendNotificationForNewTrip($trip, $driver);
            } else {
                // Для других статусов - отправляем просто обновление информации
                $result = $this->sendNotificationForExistingTrip($trip, $driver);
            }

            // ОТМЕЧАЕМ ОТПРАВКУ В БАЗЕ ДАННЫХ
            if ($result) {
                $trip->markTelegramSent();
                \Log::info('Trip marked as telegram sent', [
                    'trip_id' => $trip->id,
                    'telegram_sent' => $trip->telegram_sent,
                    'telegram_sent_count' => $trip->telegram_sent_count
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to send driver notification', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Отправка уведомления для НОВОЙ заявки (создает напоминания)
     */
    private function sendNotificationForNewTrip(Trip $trip, Driver $driver): bool
    {
        try {
            // Отправляем первое уведомление
            $this->sendFirstNotification($trip, $driver);

            // Отправляем документ если есть
            if ($trip->document) {
                $this->sendAttachmentToDriver($trip, $driver);
            }
            
            // Создаем запись для напоминаний ТОЛЬКО если ее еще нет
            $existingReminder = TripReminder::where('trip_id', $trip->id)
                ->where('driver_id', $driver->id)
                ->where('is_active', true)
                ->first();
            
            if (!$existingReminder) {
                TripReminder::create([
                    'trip_id' => $trip->id,
                    'driver_id' => $driver->id,
                    'attempt' => 1,
                    'last_reminder_at' => now(),
                    'next_reminder_at' => now()->addMinutes(30),
                    'is_active' => true,
                ]);
                
                Log::info('New trip notification sent and reminder created', [
                    'trip_id' => $trip->id,
                    'driver_id' => $driver->id
                ]);
            } else {
                Log::info('New trip notification sent (reminder already exists)', [
                    'trip_id' => $trip->id,
                    'driver_id' => $driver->id
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send new trip notification', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Отправка уведомления для СУЩЕСТВУЮЩЕЙ заявки (без напоминаний)
     */
    private function sendNotificationForExistingTrip(Trip $trip, Driver $driver): bool
    {
        try {
            $text = "ℹ️ *ОБНОВЛЕНИЕ ИНФОРМАЦИИ ПО ЗАЯВКЕ*\n\n";
            $text .= "🆔 #{$trip->id}\n";

            // Упоминаем о документе в тексте
            $documents = $trip->document;
            if (!empty($documents) && is_array($documents)) {
                $documentsCount = count($documents);
                $text .= "\n📎 *Прикреплено документов: {$documentsCount}*";
                $text .= "\n(отправляются отдельными сообщениями)\n";
            }

            if ($trip->comment) {
                $text .= "\n📍 Детали:\n";
                $text .= "{$trip->comment}\n";
            }
            
            // Кнопка только для заявок в работе или новых
            if ($trip->status === 'Новая' || $trip->status === 'В работе') {
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ Принять заявку', 'callback_data' => 'trip_take_' . $trip->id],
                        ]
                    ]
                ];
                
                Telegram::sendMessage([
                    'chat_id' => $driver->telegram_chat_id,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard)
                ]);
            } else {
                // Для других статусов без кнопки
                Telegram::sendMessage([
                    'chat_id' => $driver->telegram_chat_id,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                ]);
            }

            Log::info('Trip info update sent', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'status' => $trip->status
            ]);

            // Отправляем документ если есть
            if ($trip->document) {
                $this->sendAttachmentToDriver($trip, $driver);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send trip info update', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Первое уведомление для новой заявки
     */
    private function sendFirstNotification(Trip $trip, Driver $driver)
    {
        $text = "🚗 📋 НОВАЯ ЗАЯВКА!\n";
        $text .= "Вам нужно принять заявку❗❗❗\n\n";
        $text .= "🆔 #{$trip->id}\n";

        $documents = $trip->document;
        if (!empty($documents) && is_array($documents)) {
            $documentsCount = count($documents);
            $text .= "\n📎 *Прикреплено документов: {$documentsCount}*";
            $text .= "\n(отправляются отдельными сообщениями)\n";
        }

        if ($trip->comment) {
            $text .= "📍 Детали:\n";
            $text .= "{$trip->comment}\n";
        }

        $dispatcher = $trip->dispatcher;

        $text .= "\n👤 Диспетчер: {$dispatcher->name}\n";

        if (!empty($dispatcher->phone)) {
            $text .= "📞 {$dispatcher->phone}\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Принять заявку', 'callback_data' => 'trip_take_' . $trip->id],
                ]
            ]
        ];

        Telegram::sendMessage([
            'chat_id' => $driver->telegram_chat_id,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    /**
     * Отправка повторного напоминания (только для новых заявок)
     */
    private function sendReminderNotification(Trip $trip, Driver $driver, int $attempt)
    {
        $text = "🔔 *ПОВТОРНОЕ НАПОМИНАНИЕ!* ({$attempt}-й раз)\n";
        $text .= "Заявка всё ещё не принята! ❗❗❗\n\n";
        $text .= "🆔 #{$trip->id}\n";

        if ($trip->comment) {
            $text .= "📍 Детали:\n";
            $text .= "{$trip->comment}\n";
        }        
        
        $dispatcher = $trip->dispatcher;
        $text .= "\n👤 Диспетчер: {$dispatcher->name}\n";
        if (!empty($dispatcher->phone)) {
            $text .= "📞 {$dispatcher->phone}\n";
        }

        $createdAt = $trip->created_at ?? now();
        $diff = now()->diff($createdAt);
        $hours = $diff->h;
        $minutes = $diff->i;
        
        $text .= "\n⏱️ *Прошло времени:* ";
        if ($hours > 0) $text .= "{$hours} ч. ";
        if ($minutes > 0) $text .= "{$minutes} мин.";
        if ($hours === 0 && $minutes === 0) $text .= "менее минуты";
        
        $text .= "\n\n💡 *Срочно примите заявку!*";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Принять заявку', 'callback_data' => 'trip_take_' . $trip->id],
                ]
            ]
        ];

        Telegram::sendMessage([
            'chat_id' => $driver->telegram_chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    /**
     * Метод для отправки всех запланированных напоминаний
     * Работает ТОЛЬКО для заявок со статусом "Новая"
     */
    public function sendScheduledReminders(): int
    {
        $now = now();
        $sentCount = 0;
        
        // Находим все активные напоминания, которые пора отправить
        $reminders = TripReminder::with(['trip', 'driver'])
            ->where('is_active', true)
            ->where('next_reminder_at', '<=', $now)
            ->whereHas('trip', function($query) {
                $query->where('status', 'Новая'); // Только для новых заявок
            })
            ->whereHas('driver', function($query) {
                $query->whereNotNull('telegram_chat_id');
            })
            ->get();

        foreach ($reminders as $reminder) {
            try {
                // Отправляем повторное напоминание
                $this->sendReminderNotification(
                    $reminder->trip, 
                    $reminder->driver, 
                    $reminder->attempt
                );
                
                // Обновляем запись напоминания
                $reminder->update([
                    'last_reminder_at' => $now,
                    'next_reminder_at' => $now->addMinutes(30),
                    'attempt' => $reminder->attempt + 1,
                    'is_active' => $reminder->attempt < 7, // максимум 7 напоминаний
                ]);
                
                $sentCount++;
                
                Log::info('Trip reminder sent', [
                    'trip_id' => $reminder->trip_id,
                    'driver_id' => $reminder->driver_id,
                    'attempt' => $reminder->attempt
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to send trip reminder', [
                    'reminder_id' => $reminder->id,
                    'trip_id' => $reminder->trip_id,
                    'error' => $e->getMessage()
                ]);
                
                $reminder->update(['is_active' => false]);
            }
        }
        
        return $sentCount;
    }

    /**
     * Остановить все напоминания для заявки
     * Вызывается при смене статуса с "Новая"
     */
    public function stopRemindersForTrip(Trip $trip)
    {
        TripReminder::where('trip_id', $trip->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
            
        Log::info('Reminders stopped for trip', ['trip_id' => $trip->id]);
    }

    /**
     * Отправка уведомления об отмене заявки
     */
    public function sendCancellationNotification(Trip $trip): bool
    {
        \Log::info('Sending cancellation notification', [
            'trip_id' => $trip->id,
            'driver_id' => $trip->driver_id
        ]);

        if (!$trip->driver_id) {
            return false;
        }

        $driver = Driver::find($trip->driver_id);
        
        if (!$driver || !$driver->telegram_chat_id) {
            return false;
        }

        try {
            $text = "🚫 *ОТМЕНА ЗАЯВКИ❗❗❗*\n\n";
            $text .= "🆔 #{$trip->id}\n";
            
            if ($trip->comment) {
                $text .= "📍 Детали:\n";
                $text .= "{$trip->comment}\n";
            }
            
            $text .= "\n⚠️ Заявка была отменена диспетчером.";
            
            $dispatcher = $trip->dispatcher;

            $text .= "\n👤 Диспетчер: {$dispatcher->name}\n";

            if (!empty($dispatcher->phone)) {
                $text .= "📞 {$dispatcher->phone}\n";
            }

            Telegram::sendMessage([
                'chat_id' => $driver->telegram_chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);

            Log::info('Cancellation notification sent', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id
            ]);

            // ОТМЕЧАЕМ ОТПРАВКУ ОТМЕНЫ
            //$trip->markTelegramSent();

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send cancellation notification', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * СТАРЫЙ МЕТОД - больше не используется для автоматической отправки
     * Оставлен для совместимости, если где-то вызывается
     */
    public function sendNewTripNotification(Trip $trip)
    {
        // Этот метод больше не отправляет автоматически
        // Уведомления теперь отправляются ТОЛЬКО по кнопке через sendDriverNotification()
        \Log::info('sendNewTripNotification called but not sending (manual mode)', [
            'trip_id' => $trip->id,
            'status' => $trip->status
        ]);
        
        return false;
    }

    /**
     * Отправка файлов водителю
     */
    private function sendAttachmentToDriver(Trip $trip, Driver $driver): bool
    {
        // Получаем массив документов из поля document
        $documents = $trip->document;
        
        if (empty($documents) || !is_array($documents)) {
            return false;
        }
        
        \Log::info('Sending attachments to driver', [
            'trip_id' => $trip->id,
            'document_count' => count($documents)
        ]);
        
        $sentCount = 0;
        
        foreach ($documents as $document) {
            try {
                // Проверяем структуру документа
                if (is_string($document)) {
                    // Если это просто строка с путем
                    $filePath = storage_path('app/public/' . $document);
                    $fileName = basename($document);
                } elseif (is_array($document) && isset($document['path'])) {
                    // Если это массив с путем
                    $filePath = storage_path('app/public/' . $document['path']);
                    $fileName = $document['name'] ?? basename($document['path']);
                } else {
                    \Log::warning('Invalid document format, skipping', [
                        'trip_id' => $trip->id,
                        'document' => $document
                    ]);
                    continue;
                }
                
                // Проверяем существование файла
                if (!file_exists($filePath)) {
                    \Log::warning('Document file not found, skipping', [
                        'trip_id' => $trip->id,
                        'path' => $filePath
                    ]);
                    continue;
                }
                
                // Определяем тип файла
                $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                
                $fileTypes = [
                    'pdf' => ['📄', 'PDF документ'],
                    'doc' => ['📝', 'Word документ'],
                    'docx' => ['📝', 'Word документ'],
                    'jpg' => ['🖼️', 'Фото'],
                    'jpeg' => ['🖼️', 'Фото'],
                    'png' => ['🖼️', 'Фото'],
                    'xls' => ['📊', 'Excel файл'],
                    'xlsx' => ['📊', 'Excel файл'],
                    'txt' => ['📝', 'Текстовый файл'],
                    'zip' => ['🗜️', 'Архив ZIP'],
                    'rar' => ['🗜️', 'Архив RAR'],
                    'csv' => ['📊', 'Файл CSV'],
                ];
                
                $fileIcon = $fileTypes[$extension][0] ?? '📎';
                $fileTypeName = $fileTypes[$extension][1] ?? 'файл';
                
                // Получаем размер файла
                $fileSizeBytes = filesize($filePath);
                $fileSize = '';
                
                if ($fileSizeBytes) {
                    if ($fileSizeBytes < 1024) {
                        $fileSize = " ({$fileSizeBytes} B)";
                    } elseif ($fileSizeBytes < 1048576) {
                        $fileSize = " (" . round($fileSizeBytes / 1024, 1) . " KB)";
                    } else {
                        $fileSize = " (" . round($fileSizeBytes / 1048576, 1) . " MB)";
                    }
                }
                
                // Отправляем документ в Telegram
                if (in_array($extension, ['jpg', 'jpeg', 'png'])) {
                    // Для изображений отправляем как фото
                    Telegram::sendPhoto([
                        'chat_id' => $driver->telegram_chat_id,
                        'photo' => fopen($filePath, 'r'),
                        'caption' => "{$fileIcon} {$fileTypeName} к заявке #{$trip->id}\n📂 {$fileName}{$fileSize}",
                    ]);
                } else {
                    // Для остальных файлов - как документ
                    Telegram::sendDocument([
                        'chat_id' => $driver->telegram_chat_id,
                        'document' => fopen($filePath, 'r'),
                        'caption' => "{$fileIcon} {$fileTypeName} к заявке #{$trip->id}\n📂 {$fileName}{$fileSize}",
                    ]);
                }
                
                $sentCount++;
                
                // Небольшая задержка между отправками (1 секунда)
                if ($sentCount < count($documents)) {
                    sleep(1);
                }
                
                \Log::info('Attachment sent successfully', [
                    'trip_id' => $trip->id,
                    'file_name' => $fileName,
                    'file_type' => $extension,
                    'sent_count' => $sentCount
                ]);
                
            } catch (\Exception $e) {
                \Log::error('Failed to send attachment', [
                    'trip_id' => $trip->id,
                    'document' => $document,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        \Log::info('All attachments processed', [
            'trip_id' => $trip->id,
            'total_documents' => count($documents),
            'successfully_sent' => $sentCount
        ]);
        
        return $sentCount > 0;
    }
}