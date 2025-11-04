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

        // СРАЗУ отвечаем Telegram в течение 1-2 секунд
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
        ]);

        // Быстро находим водителя (кешируем запрос)
        $driver = Cache::remember("driver_chat_{$chatId}", 300, function() use ($chatId) {
            return Driver::where('telegram_chat_id', $chatId)->first();
        });
        
        if (!$driver) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Ошибка: водитель не найден'
            ]);
            return;
        }

        // Отправляем "загрузка" сообщение быстро
        $loadingMessage = Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => '⏳ Загружаем...',
        ]);

        // Теперь обрабатываем данные (уже после ответа Telegram)
        $this->processCallbackData($callbackData, $driver, $chatId, $loadingMessage->getMessageId());
    }

    /**
     * Медленная обработка данных (после ответа Telegram)
     */
    private function processCallbackData($callbackData, $driver, $chatId, $loadingMessageId)
    {
        try {
            // Удаляем сообщение "Загружаем..." если оно есть
            if ($loadingMessageId) {
                try {
                    Telegram::deleteMessage([
                        'chat_id' => $chatId,
                        'message_id' => $loadingMessageId
                    ]);
                } catch (\Exception $e) {
                    // Игнорируем ошибку если сообщение уже удалено
                }
            }

            if (str_starts_with($callbackData, 'trip_')) {
                $this->handleTripAction($callbackData, $driver, $chatId);
            } elseif (str_starts_with($callbackData, 'status_')) {
                $this->handleStatusChange($callbackData, $driver, $chatId);
            } else {
                $this->handleMenuAction($callbackData, $driver, $chatId);
            }
        } catch (\Exception $e) {
            \Log::error('Process callback error: ' . $e->getMessage());
            
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Ошибка при обработке запроса'
            ]);
        }
    }

    /**
     * Обработка действий с заявками (принять, отказаться, детали)
     */
    private function handleTripAction($callbackData, $driver, $chatId, $messageId)
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
        }
    }

    /**
     * Принять заявку
     */
    private function takeTrip($trip, $driver, $chatId)
    {
        // Проверяем, не взята ли уже заявка
        if ($trip->driver_id && $trip->driver_id != $driver->id) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Заявка уже взята другим водителем'
            ]);
            return;
        }

        $trip->update([
            'driver_id' => $driver->id,
            'status' => 'В пути'
        ]);

        // Показываем меню управления заявкой
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
                'status' => 'Новая'
            ]);
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => '❌ Вы отказались от заявки #' . $trip->id
        ]);
    }

    /**
     * Меню управления принятой заявкой
     */
    private function showTripManagement($trip, $chatId)
    {
        $text = "✅ Вы приняли заявку #{$trip->id}\n\n";
        $text .= "📋 Детали:\n";
        $text .= "• Маршрут: {$trip->from_city} → {$trip->to_city}\n";
        $text .= "• Клиент: {$trip->client_name}\n";
        $text .= "• Телефон: {$trip->client_phone}\n\n";
        $text .= "🚦 Текущий статус: В пути";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📄 Путевой лист', 'callback_data' => 'waybill_' . $trip->id],
                    ['text' => '📍 Изменить статус', 'callback_data' => 'status_menu_' . $trip->id],
                ],
                [
                    ['text' => '📞 Контакты', 'callback_data' => 'contacts_' . $trip->id],
                    ['text' => '🔄 Обновить', 'callback_data' => 'refresh_trip_' . $trip->id],
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
                ->whereIn('status', ['В пути', 'Загрузка', 'Выгрузка', 'В работе'])
                ->count();
        });
        
        $totalTripsCount = Cache::remember("driver_{$driver->id}_total_trips", 30, function() use ($driver) {
            return Trip::where('driver_id', $driver->id)->count();
        });
        
        $availableTripsCount = Cache::remember("available_trips_count", 30, function() {
            return Trip::where(function($query) {
                    $query->whereNull('driver_id')
                        ->orWhere('driver_id', '');
                })
                ->whereIn('status', ['Новая', 'Ожидает'])
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
        $trips = Trip::where(function($query) {
                $query->whereNull('driver_id')
                    ->orWhere('driver_id', '');
            })
            ->where('status', 'Новая')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'from_city', 'to_city', 'client_name', 'load_date']);

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
            ->whereIn('status', ['В пути', 'Загрузка', 'Выгрузка'])
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
    public function handleDocument($update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $document = $message->getDocument();
        
        $driver = Driver::where('telegram_chat_id', $chatId)->first();
        
        if (!$driver) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Водитель не найден'
            ]);
            return;
        }

        // Получаем файл
        $file = Telegram::getFile(['file_id' => $document->getFileId()]);
        $filePath = $file->getFilePath();
        
        // Скачиваем файл
        $fileContent = Telegram::downloadFile($filePath, 'waybills');
        
        // Сохраняем информацию о файле в базу
        // Нужно будет создать модель Waybill
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => '✅ Путевой лист получен и сохранен'
        ]);
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
}