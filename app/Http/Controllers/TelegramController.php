<?php

namespace App\Http\Controllers;

use App\Models\Driver; 
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Laravel\Facades\Telegram;
use Carbon\Carbon;

class TelegramController extends Controller
{
    public function webhook(Request $request)
    {
        \Log::info('Telegram webhook called', [
            'content' => $request->getContent()
        ]);
        
        $update = Telegram::getWebhookUpdate();
        
        // Авторегистрация водителя
        if ($update->getMessage()) {
            $this->autoRegisterDriver($update);
            
            // Обработка документов (путевых листов)
            if ($update->getMessage()->has('document')) {
                $this->handleDocument($update);
                return response()->json(['status' => 'ok']);
            }
            
            // Обработка фото (тоже может быть путевым листом)
            if ($update->getMessage()->has('photo')) {
                $this->handlePhoto($update);
                return response()->json(['status' => 'ok']);
            }
        }
        
        // Обрабатываем callback queries отдельно
        if ($update->getCallbackQuery()) {
            $this->handleCallbackQuery($update);
            return response()->json(['status' => 'ok']);
        }
        
        // Обрабатываем обычные команды
        Telegram::commandsHandler(true);
        
        return response()->json(['status' => 'ok']);
    }

    private function handleCallbackQuery($update)
    {
        $callbackQuery = $update->getCallbackQuery();
        $callbackData = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $callbackQueryId = $callbackQuery->getId();

        try {
            // Пытаемся сразу ответить Telegram - если не получится, callback устарел
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
            ]);
        } catch (\Exception $e) {
            \Log::warning('Callback query expired or invalid: ' . $callbackQueryId);
            return; // Просто игнорируем устаревший callback
        }

        $driver = Driver::where('telegram_chat_id', $chatId)->first();
        
        if (!$driver) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Ошибка: водитель не найден'
            ]);
            return;
        }

        // Дальнейшая обработка...
        $this->processCallbackData($callbackData, $driver, $chatId);
    }

    /**
     * Медленная обработка данных (после ответа Telegram)
     */
    private function processCallbackData($callbackData, $driver, $chatId)
    {
        try {
            if (str_starts_with($callbackData, 'trip_')) {
                $this->handleTripAction($callbackData, $driver, $chatId);
            } elseif (str_starts_with($callbackData, 'status_')) {
                $this->handleStatusChange($callbackData, $driver, $chatId);
            } elseif (str_starts_with($callbackData, 'waybill_')) {
                $this->handleWaybill($callbackData, $driver, $chatId);
            } else {
                $this->handleMenuAction($callbackData, $driver, $chatId);
            }
        } catch (\Exception $e) {
            \Log::error('Process callback error: ' . $e->getMessage());
            
            // Только если ошибка не связана с устаревшим callback
            if (!str_contains($e->getMessage(), 'too old') && !str_contains($e->getMessage(), 'timeout')) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Ошибка при обработке запроса'
                ]);
            }
        }
    }

    /**
     * Обработка смены статусов
     */
    private function handleStatusChange($callbackData, $driver, $chatId)
    {
        $parts = explode('_', $callbackData);
        $action = $parts[1]; // menu, inprogress, completed и т.д.
        $tripId = $parts[2];
        
        $trip = Trip::find($tripId);
        
        if (!$trip) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Заявка не найдена'
            ]);
            return;
        }

        switch ($action) {
            case 'menu':
                $this->showStatusMenu($trip, $chatId);
                break;
            case 'inprogress':
                $this->changeTripStatus($trip, 'В работе', $chatId);
                break;
            case 'completed':
                $this->changeTripStatus($trip, 'Выполнена', $chatId);
                break;
            case 'postponed':
                $this->changeTripStatus($trip, 'Перенесена', $chatId);
                break;
            case 'rejected':
                $this->changeTripStatus($trip, 'Отклонена', $chatId);
                break;
        }
    }

    /**
     * Обработка действий с заявками (принять, отказаться, детали)
     */
    private function handleTripAction($callbackData, $driver, $chatId)
    {
        $parts = explode('_', $callbackData);
        $action = $parts[1]; // take, reject, details
        $tripId = $parts[2];
        
        $trip = Trip::find($tripId);
        
        if (!$trip) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Заявка не найдена'
            ]);
            return;
        }

        // ПРОВЕРЯЕМ ЧТО ЗАЯВКА ПРИНАДЛЕЖИТ ЭТОМУ ВОДИТЕЛЮ
        if ($trip->driver_id != $driver->id) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Эта заявка назначена другому водителю'
            ]);
            return;
        }

        switch ($action) {
            case 'take':
                $this->takeTrip($trip, $driver, $chatId);
                break;
            case 'reject':
                $this->rejectTrip($trip, $driver, $chatId);
                break;
            case 'details':
                $this->showTripDetails($trip, $chatId);
                break;
            default:
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Неизвестное действие'
                ]);
        }
    }

    /**
     * Показать детали заявки
     */
    private function showTripDetails($trip, $chatId)
    {
        $text = "📋 ДЕТАЛИ ЗАЯВКИ #{$trip->id}\n\n";
        $text .= "📍 Маршрут: {$trip->from_city} → {$trip->to_city}\n";
        $text .= "👤 Клиент: {$trip->client_name}\n";
        $text .= "📞 Телефон: {$trip->client_phone}\n";
        $text .= "📅 Загрузка: " . Carbon::parse($trip->load_date)->format('d.m.Y H:i') . "\n";
        $text .= "🚚 Доставка: " . Carbon::parse($trip->delivery_date)->format('d.m.Y H:i') . "\n";
        $text .= "📊 Статус: {$trip->status}\n\n";
        
        // Добавляем информацию о грузе если есть
        if ($trip->cargo_type) {
            $text .= "📦 Груз: {$trip->cargo_type}\n";
        }
        if ($trip->cargo_weight) {
            $text .= "⚖️ Вес: {$trip->cargo_weight} кг\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Взять заявку', 'callback_data' => 'trip_take_' . $trip->id],
                    ['text' => '❌ Отказаться', 'callback_data' => 'trip_reject_' . $trip->id],
                ],
                [
                    ['text' => '🔙 Назад к списку', 'callback_data' => 'menu_available_trips'],
                ]
            ]
        ];

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    /**
     * Принять заявку
     */
    private function takeTrip($trip, $driver, $chatId)
    {
        if ($trip->driver_id && $trip->driver_id != $driver->id) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Заявка уже взята другим водителем'
            ]);
            return;
        }

        $trip->update([
            'driver_id' => $driver->id,
            'status' => 'В работе'  // ← МЕНЯЕМ СТАТУС
        ]);

        $this->showTripManagement($trip, $chatId);
    }

    /**
     * Отказаться от заявки
     */
    private function rejectTrip($trip, $driver, $chatId)
    {
        if ($trip->driver_id == $driver->id) {
            $trip->update([
                'driver_id' => null,
                'status' => 'Отклонена'  // ← МЕНЯЕМ СТАТУС
            ]);
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => '❌ Вы отказались от заявки #' . $trip->id
        ]);
    }

    /**
     * Смена статуса заявки
     */
    private function changeTripStatus($trip, $newStatus, $chatId)
    {
        $trip->update([
            'status' => $newStatus
        ]);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ Статус заявки #{$trip->id} изменен на: {$newStatus}"
        ]);

        // Показываем обновленное меню управления
        $this->showTripManagement($trip, $chatId);
    }

    /**
     * Меню управления принятой заявкой
     */
    private function showTripManagement($trip, $chatId)
    {
        // Разный текст в зависимости от статуса
        if ($trip->status == 'Выполнена') {
            $text = "✅ ЗАЯВКА ВЫПОЛНЕНА #{$trip->id}\n\n";
            $text .= "📋 Детали:\n";
            $text .= "• Маршрут: {$trip->from_city} → {$trip->to_city}\n";
            $text .= "• Клиент: {$trip->client_name}\n";
            $text .= "• Телефон: {$trip->client_phone}\n\n";
            $text .= "🎉 Заявка успешно завершена!";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📄 Прикрепить путевой лист', 'callback_data' => 'waybill_' . $trip->id],
                    ],
                    [
                        ['text' => '📊 К списку заявок', 'callback_data' => 'menu_active_trips'],
                    ]
                ]
            ];
            
        } else {
            // Стандартное меню для активных заявок
            $text = "✅ ВАША ЗАЯВКА #{$trip->id}\n\n";
            $text .= "📋 Детали:\n";
            $text .= "• Маршрут: {$trip->from_city} → {$trip->to_city}\n";
            $text .= "• Клиент: {$trip->client_name}\n";
            $text .= "• Телефон: {$trip->client_phone}\n\n";
            $text .= "🚦 Текущий статус: {$trip->status}";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📄 Путевой лист', 'callback_data' => 'waybill_' . $trip->id],
                        ['text' => '📍 Изменить статус', 'callback_data' => 'status_menu_' . $trip->id],
                    ],
                    [
                        ['text' => '🔄 Обновить', 'callback_data' => 'refresh_trip_' . $trip->id],
                    ]
                ]
            ];
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    /**
     * Обработка меню (главное меню, списки заявок)
     */
    private function handleMenuAction($callbackData, $driver, $chatId)
    {
        switch ($callbackData) {
            case 'menu_available_trips':
                $this->showAvailableTrips($driver, $chatId);
                break;
            case 'menu_active_trips':
                $this->showActiveTripsMenu($driver, $chatId);
                break;
            case 'menu_send_waybill':
                $this->askWaybillTrip($chatId);
                break;
            case 'trips_refresh':
                $this->showMainMenu($driver, $chatId);
                break;
            default:
                $this->handleLegacyCallback($callbackData, $driver, $chatId);
        }
    }

    /**
     * Главное меню (/mytrips)
     */
    public function showMainMenu($driver, $chatId, $messageId = null)
    {
        // Кешируем запросы на 30 секунд
        $activeTripsCount = Cache::remember("driver_{$driver->id}_active_trips", 30, function() use ($driver) {
            return Trip::where('driver_id', $driver->id)
                ->where('status', 'В работе')   
                ->count();
        });
        
        $totalTripsCount = Cache::remember("driver_{$driver->id}_total_trips", 30, function() use ($driver) {
            return Trip::where('driver_id', $driver->id)->count();
        });
        

        $availableTripsCount = Cache::remember("driver_{$driver->id}_available_trips", 30, function() use ($driver) {
            return Trip::where('driver_id', $driver->id)
                ->where('status', 'Новая')  
                ->count();
        });

        $text = "🚗 МОИ ЗАЯВКИ\n\n";
        $text .= "📊 Статистика:\n";
        $text .= "• Доступно: {$availableTripsCount}\n";
        $text .= "• Активные: {$activeTripsCount}\n";
        $text .= "• Всего: {$totalTripsCount}\n\n";
 
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📋 Доступные заявки', 'callback_data' => 'menu_available_trips'],
                    ['text' => '🚗 В работе', 'callback_data' => 'menu_active_trips'],
                ],
                [
                    ['text' => '📤 Отправить путевой', 'callback_data' => 'menu_send_waybill'],
                    ['text' => '🔄 Обновить', 'callback_data' => 'trips_refresh'],
                ]
            ]
        ];

        if ($messageId) {
            Telegram::editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);
        } else {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);
        }
    }

    /**
     * Показать доступные заявки
     */
    private function showAvailableTrips($driver, $chatId, $messageId = null)
    {
        // Сначала загружаем данные
        $trips = Trip::where('driver_id', $driver->id)
            ->where('status', 'Новая')  // ← ТОЛЬКО В РАБОТЕ
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        if ($trips->isEmpty()) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "📭 Нет доступных заявок"
            ]);
            return;
        }

        // Отправляем заголовок
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "📋 ДОСТУПНЫЕ ЗАЯВКИ ({$trips->count()})"
        ]);

        // Отправляем каждую заявку отдельным сообщением
        foreach ($trips as $trip) {
            $text = "📋 ЗАЯВКА #{$trip->id}\n";
            $text .= "Маршрут: {$trip->from_city} → {$trip->to_city}\n";
            $text .= "Клиент: {$trip->client_name}\n";
            $text .= "Загрузка: " . Carbon::parse($trip->load_date)->format('d.m.Y H:i');

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Взять заявку', 'callback_data' => 'trip_take_' . $trip->id],
                        ['text' => '👀 Подробнее', 'callback_data' => 'trip_details_' . $trip->id],
                    ]
                ]
            ];

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);
        }
    }

    /**
     * Показать заявки в работе
     */
    private function showActiveTripsMenu($driver, $chatId)
    {
        $trips = Trip::where('driver_id', $driver->id)
            ->where('status', 'В работе')  // ← ТОЛЬКО В РАБОТЕ
            ->orderBy('load_date', 'asc')
            ->get();

        if ($trips->isEmpty()) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "🚗 Нет активных заявок"
            ]);
            return;
        }

        foreach ($trips as $trip) {
            $text = "🚗 ЗАЯВКА #{$trip->id}\n";
            $text .= "Маршрут: {$trip->from_city} → {$trip->to_city}\n";
            $text .= "Статус: {$trip->status}\n";
            $text .= "Доставка до: " . Carbon::parse($trip->delivery_date)->format('d.m.Y H:i');

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📍 Изменить статус', 'callback_data' => 'status_menu_' . $trip->id],
                        ['text' => '📄 Путевой лист', 'callback_data' => 'waybill_' . $trip->id],
                    ]
                ]
            ];

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode($keyboard)
            ]);
        }
    }

    private function autoRegisterDriver($update)
    {
        $message = $update->getMessage();
        $from = $message->getFrom();
        $chatId = $from->getId();
        $username = $from->getUsername();
        $firstName = $from->getFirstName();
        $lastName = $from->getLastName();
        
        // Ищем водителя по telegram_username
        $driver = \App\Models\Driver::where('telegram_username', $username)->first();
        
        // Если не нашли по username, ищем по имени
        if (!$driver) {
            $driver = \App\Models\Driver::where('name', 'like', "%{$firstName}%")->first();
        }
        
        if ($driver && !$driver->telegram_chat_id) {
            $driver->update([
                'telegram_chat_id' => $chatId,
                'telegram_username' => $username // обновляем username на актуальный
            ]);
            
            \Log::info("Driver auto-registered", [
                'driver_id' => $driver->id,
                'name' => $driver->name,
                'chat_id' => $chatId
            ]);
            
            // Отправляем приветственное сообщение
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ Вы успешно зарегистрированы как: {$driver->name}\n\nТеперь вы будете получать заявки и можете использовать команды:\n/mytrips - ваши заявки\n/help - связь с диспетчером",
                'parse_mode' => 'HTML'
            ]);
        }
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
            $message .= "🆔 #{$trip->id} - {$trip->name}\n";
            $message .= "📅 {$trip->date} - 👤 {$trip->client_name}\n\n";
        }

        return $message;
    }

    private function getTripsKeyboard()
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🟡 Активные',
                        'callback_data' => 'trips_active'
                    ],
                    [
                        'text' => '📅 Сегодня',
                        'callback_data' => 'trips_today'
                    ],
                ],
                [
                    [
                        'text' => '📆 Неделя',
                        'callback_data' => 'trips_week'
                    ],
                    [
                        'text' => '📅 Месяц',
                        'callback_data' => 'trips_month'
                    ],
                ],
                [
                    [
                        'text' => '📊 Все заявки',
                        'callback_data' => 'trips_all'
                    ],
                    [
                        'text' => '🔄 Обновить',
                        'callback_data' => 'trips_refresh'
                    ],
                ]
            ]
        ];
    }

    /**
     * Обработка документов (путевые листы)
     */
    private function handleDocument($update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $document = $message->getDocument();
        
        $waitingTripId = Cache::get("waiting_waybill_{$chatId}");
        
        if (!$waitingTripId) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Сначала выберите заявку для прикрепления путевого листа'
            ]);
            return;
        }

        $driver = Driver::where('telegram_chat_id', $chatId)->first();
        $trip = Trip::find($waitingTripId);
        
        if (!$driver || !$trip || $trip->driver_id != $driver->id) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Ошибка: заявка не найдена или не принадлежит вам'
            ]);
            return;
        }

        try {
            // Получаем файл через SDK
            $file = Telegram::getFile([
                'file_id' => $document->getFileId()
            ]);
            
            // Скачиваем файл через SDK (с указанием пути)
            $tempPath = storage_path('app/temp_document_' . time() . '_' . $document->getFileName());
            Telegram::downloadFile($file, $tempPath);
            
            // Читаем содержимое файла
            $fileContent = file_get_contents($tempPath);
            
            // Генерируем уникальное имя файла
            $originalName = $document->getFileName();
            $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'pdf';
            $fileName = 'waybill_' . $trip->id . '_' . time() . '.' . $extension;
            $storagePath = 'waybills/' . $fileName;
            
            // Сохраняем файл в постоянное хранилище
            \Storage::disk('public')->put($storagePath, $fileContent);
            
            // Удаляем временный файл
            unlink($tempPath);
            
            // Сохраняем в базу
            \App\Models\Waybill::create([
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'file_path' => $storagePath,
                'file_name' => $fileName,
                'original_name' => $originalName,
                'file_size' => $document->getFileSize(),
                'mime_type' => $document->getMimeType(),
                'uploaded_at' => now(),
            ]);

            // Обновляем заявку
            $trip->update([
                'has_waybill' => true
            ]);

            // Очищаем состояние ожидания
            Cache::forget("waiting_waybill_{$chatId}");

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ Путевой лист прикреплен к заявке #{$trip->id}\n\nФайл: {$originalName}"
            ]);
            
            \Log::info('Waybill document saved', [
                'trip_id' => $trip->id,
                'file_name' => $originalName,
                'file_path' => $storagePath
            ]);

        } catch (\Exception $e) {
            \Log::error('Error saving waybill document', [
                'error' => $e->getMessage(),
                'trip_id' => $trip->id ?? 'unknown'
            ]);
            
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Ошибка при сохранении файла'
            ]);
        }
    }

    /**
     * Обработка фото (путевые листы)
     */
    private function handlePhoto($update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        
        $waitingTripId = Cache::get("waiting_waybill_{$chatId}");
        
        if (!$waitingTripId) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Сначала выберите заявку для прикрепления путевого листа'
            ]);
            return;
        }

        $trip = Trip::find($waitingTripId);
        $driver = Driver::where('telegram_chat_id', $chatId)->first();
        
        if (!$trip || !$driver || $trip->driver_id != $driver->id) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Ошибка при прикреплении путевого листа'
            ]);
            return;
        }

        try {
            // Получаем фото
            $photos = $message->getPhoto();
            
            // Проверяем что есть фото
            if (empty($photos)) {
                throw new \Exception('No photos found in message');
            }
            
            // Получаем самое качественное фото (последний элемент массива)
            // Вместо end() используем прямой доступ по индексу
            $lastIndex = count($photos) - 1;
            $photo = $photos[$lastIndex];
            
            // Используем методы класса PhotoSize
            $fileId = $photo->getFileId();
            $fileSize = $photo->getFileSize();
            
            \Log::info('Processing photo', [
                'file_id' => $fileId,
                'file_size' => $fileSize,
                'photo_class' => get_class($photo),
                'photos_count' => count($photos),
                'last_index' => $lastIndex
            ]);
            
            // Получаем файл через SDK
            $file = Telegram::getFile([
                'file_id' => $fileId
            ]);
            
            // Скачиваем файл через SDK (с указанием пути)
            $tempPath = storage_path('app/temp_photo_' . time() . '.jpg');
            Telegram::downloadFile($file, $tempPath);
            
            // Читаем содержимое файла
            $fileContent = file_get_contents($tempPath);
            
            // Генерируем уникальное имя файла
            $fileName = 'waybill_' . $trip->id . '_' . time() . '.jpg';
            $storagePath = 'waybills/' . $fileName;
            
            // Сохраняем файл
            \Storage::disk('public')->put($storagePath, $fileContent);
            
            // Удаляем временный файл
            unlink($tempPath);
            
            // Сохраняем в базу
            \App\Models\Waybill::create([
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'file_path' => $storagePath,
                'file_name' => $fileName,
                'original_name' => 'photo_' . time() . '.jpg',
                'file_size' => $fileSize,
                'mime_type' => 'image/jpeg',
                'uploaded_at' => now(),
            ]);

            // Обновляем заявку
            $trip->update([
                'has_waybill' => true
            ]);

            // Очищаем состояние ожидания
            Cache::forget("waiting_waybill_{$chatId}");

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ Путевой лист (фото) прикреплен к заявке #{$trip->id}"
            ]);

        } catch (\Exception $e) {
            \Log::error('Error saving waybill photo', [
                'error' => $e->getMessage(),
                'trip_id' => $trip->id ?? 'unknown',
                'photos_count' => count($photos ?? [])
            ]);
            
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Ошибка при сохранении фото'
            ]);
        }
    }

    /**
     * Обработка прикрепления путевого листа
     */
    private function handleWaybill($callbackData, $driver, $chatId)
    {
        $parts = explode('_', $callbackData);
        $tripId = $parts[1];
        
        $trip = Trip::find($tripId);
        
        if (!$trip) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Заявка не найдена'
            ]);
            return;
        }

        // Проверяем что заявка принадлежит водителю
        if ($trip->driver_id != $driver->id) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Эта заявка не назначена вам'
            ]);
            return;
        }

        // Запрашиваем отправку документа
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "📄 Отправьте путевой лист для заявки #{$tripId}\n\nПрикрепите фото или документ:",
            'reply_markup' => json_encode([
                'force_reply' => true,
                'input_field_placeholder' => '📎 Прикрепите файл...'
            ])
        ]);

        // Сохраняем состояние что ждем путевой лист для этой заявки
        // Можно использовать кеш или сессию
        Cache::put("waiting_waybill_{$chatId}", $tripId, 300); // 5 минут
    }

    /**
     * Запрос номера заявки для путевого листа
    */
    private function askWaybillTrip($chatId)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => '📄 Отправка путевого листа\n\nВведите номер заявки:',
            'reply_markup' => json_encode([
                'force_reply' => true,
                'input_field_placeholder' => 'Например: 12345'
            ])
        ]);
        
        // Здесь нужно сохранить состояние, что ждем номер заявки
        // Можно использовать кеш или таблицу состояний
    }

    /**
     * Для обратной совместимости со старыми callback
    */ 
    private function handleLegacyCallback($callbackData, $driver, $chatId, $messageId)
    {
        switch ($callbackData) {
            case 'trips_active':
                $trips = $this->getActiveTrips($driver);
                $title = "🟡 Активные заявки";
                break;
            case 'trips_all':
                $trips = $this->getAllTrips($driver);
                $title = "📊 Все заявки";
                break;
            default:
                $trips = $this->getAllTrips($driver);
                $title = "📊 Заявки";
        }

        $message = $this->formatTripsMessage($title, $trips);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($this->getTripsKeyboard())
        ]);
    }

    private function showStatusMenu($trip, $chatId)
    {
        $text = "📍 ИЗМЕНИТЬ СТАТУС #{$trip->id}\n\n";
        $text .= "Текущий статус: {$trip->status}\n\n";
        $text .= "Выберите новый статус:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚗 В работе', 'callback_data' => 'status_inprogress_' . $trip->id],
                    ['text' => '✅ Выполнена', 'callback_data' => 'status_completed_' . $trip->id],
                ],
                [
                    ['text' => '📅 Перенесена', 'callback_data' => 'status_postponed_' . $trip->id],
                    ['text' => '❌ Отклонить', 'callback_data' => 'status_rejected_' . $trip->id],
                ],
                [
                    ['text' => '🔙 Назад', 'callback_data' => 'trip_details_' . $trip->id],
                ]
            ]
        ];

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}