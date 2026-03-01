<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TripResource\Pages;
use App\Filament\Resources\TripResource\RelationManagers;
use App\Models\Trip;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Set;
use Filament\Tables\Filters\Indicator;
use Carbon\Carbon;
//use pxlrbt\FilamentExcel\Exports\ExcelExport;
use Filament\Actions;
use Filament\Notifications\Notification;
// use Filament\Forms\Components\TextInput;
// use pxlrbt\FilamentExcel\Columns\Column;
// use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use MoveMoveIo\DaData\Enums\CompanyStatus;
use MoveMoveIo\DaData\Enums\CompanyType;
use MoveMoveIo\DaData\Facades\DaDataCompany;
use App\Models\Client;
use Filament\Forms\Components\Actions\Action;

class TripResource extends Resource
{
    protected static ?string $model = Trip::class;
    
    protected static ?int $navigationSort = 10;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Заявки';
    protected static ?string $modelLabel = 'Заявка';
    protected static ?string $pluralModelLabel = 'Заявки';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('trigger_calculation')
                    ->default(1)
                    ->reactive()
                    ->afterStateHydrated(function ($set, $get) {
                        // Вызываем расчет при загрузке формы
                        self::calculateFinancialFields($set, $get);
                }),
                Forms\Components\Section::make('Основная информация')
                    ->schema([
                        Forms\Components\TextInput::make('id')
                            ->label('Номер заявки')
                            ->disabled() // Делаем поле нередактируемым
                            ->dehydrated() 
                            ->visible(fn ($livewire) => 
                                $livewire instanceof Pages\EditTrip // Показываем только при редактировании
                            ),
                        Forms\Components\DatePicker::make('date')
                            ->label('Дата')
                            ->native(false),
                        // Forms\Components\TimePicker::make('time')
                        //     ->label('Время')
                        //     ->seconds(false),
                        Forms\Components\Select::make('dispatcher_id')  
                            ->label('Диспетчер')
                            ->relationship('dispatcher', 'name')  
                            ->searchable()
                            ->preload()
                            ->default(auth()->id()) // Текущий пользователь по умолчанию
                             ->options(\App\Models\User::where('id', '!=', 1)->pluck('name', 'id')), // Исключаем админа
                        // Forms\Components\TextInput::make('client_name')
                        //     ->label('Наименование клиента')
                        //     ->required()
                        //     ->maxLength(255),
                        // Forms\Components\Select::make('client_type')
                        //     ->label('Тип клиента')
                        //     ->options([
                        //         'П' => 'П',
                        //         'Н' => 'Н',
                        //     ])
                        //     ->default('П'),
                        Forms\Components\Select::make('status')
                            ->label('Статус заявки')
                            ->options([
                                'Новая'      => 'Новая',    
                                'В работе'   => 'В работе',
                                'Выполнена'  => 'Выполнена', 
                                'Отменена'   => 'Отменена',
                                'Перенесена' => 'Перенесена', 
                                'Отклонена'  => 'Отклонена', 
                                'Ремонт'     => 'Ремонт',
                            ])
                            ->default('Новая')
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                // Если статус меняется с "Отменена" на другой - очищаем поле reason
                                if ($state !== 'Отменена') {
                                    $set('reason', null);
                                }
                            }),
                        Forms\Components\Textarea::make('reason')
                            ->label('Причина отмены')
                            ->helperText('Обязательно укажите причину отмены заявки')
                            ->rows(2)
                            ->maxLength(500)
                            ->required(fn (Forms\Get $get): bool => 
                                in_array($get('status'), ['Отменена'])
                            )
                            ->visible(fn (Forms\Get $get): bool => 
                                in_array($get('status'), ['Отменена'])
                            )
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('document')
                            ->label('Документ')
                            ->helperText('Загрузите один или несколько файлов')
                            ->multiple()
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'image/jpeg',
                                'image/png',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->maxSize(10240)  
                            ->directory('trip-documents') 
                            ->visibility('public')
                            ->preserveFilenames()
                            ->downloadable()
                            ->openable()
                            ->previewable(true)
                            ->reorderable() 
                            ->appendFiles() 
                            ->nullable()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('comment')
                            ->label('Комментарий')
                            ->helperText('Дополнительная информация')
                            ->rows(3)
                            ->maxLength(1000)
                            ->nullable()
                            ->columnSpanFull(), 
                    ])->columns(2),
                
                Forms\Components\Section::make('Техническая информация')
                    ->schema([
                        Forms\Components\Select::make('driver_id')
                            ->label('Водитель')
                            ->relationship('driver', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('type_t')
                            ->label('Тип техники')
                            ->options([
                                'Автовышка'        => 'Автовышка',    
                                'Машина прикрытия' => 'Машина прикрытия',
                            ]),
                        Forms\Components\TextInput::make('height')
                            ->label('Высота')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('car_number')
                            ->label('№ авто')
                            ->maxLength(255),

                        Forms\Components\Select::make('client_id')
                            ->label('Заказчик')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->getSearchResultsUsing(function (string $search): array {
                                return Client::query()
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('inn', 'like', "%{$search}%")
                                    ->limit(10)
                                    ->get()
                                    ->mapWithKeys(fn ($client) => [
                                        $client->id => $client->name . ($client->inn ? ' (ИНН: ' . $client->inn . ')' : '')
                                    ])
                                    ->toArray();
                            })
                            ->getOptionLabelUsing(fn ($value): ?string => 
                                Client::find($value)?->name . (Client::find($value)?->inn ? ' (ИНН: ' . Client::find($value)?->inn . ')' : '')
                            )
                            ->suffixAction(
                                Action::make('create')
                                    ->label('Создать заказчика')
                                    ->icon('heroicon-o-plus')
                                    ->form([
                                        Forms\Components\Section::make('🔍 Поиск организации')
                                            ->schema([
                                                Forms\Components\Select::make('company_search')
                                                    ->label('')
                                                    ->searchable()
                                                    ->placeholder('Начните вводить название или ИНН организации...')
                                                    ->getSearchResultsUsing(function (string $search): array {
                                                        if (strlen($search) < 2) {
                                                            return [];
                                                        }

                                                        $suggestions = DaDataCompany::prompt($search, 5, [CompanyStatus::ACTIVE], 2);

                                                        $options = [];
                                                        foreach ($suggestions['suggestions'] ?? [] as $suggestion) {
                                                            $inn = $suggestion['data']['inn'] ?? '';
                                                            $name = $suggestion['value'] ?? '';
                                                            $address = $suggestion['data']['address']['value'] ?? '';
                                                            
                                                            if (!empty($inn) && !empty($name)) {
                                                                $displayText = '<div class="flex flex-col">';
                                                                $displayText .= '<div class="font-medium">' . $name . '</div>';
                                                                if ($address) {
                                                                    $displayText .= '<div class="text-xs text-gray-500">📍 ' . $address . '</div>';
                                                                }
                                                                $displayText .= '<div class="text-xs text-gray-500">ИНН: ' . $inn . '</div>';
                                                                $displayText .= '</div>';
                                                                
                                                                $options[$name . '|' . $inn . '|' . $address] = $displayText;
                                                            }
                                                        }

                                                        return $options;
                                                    })
                                                    ->afterStateUpdated(function ($state, callable $set) {
                                                        if ($state) {
                                                            $parts = explode('|', $state);
                                                            if (count($parts) >= 2) {
                                                                $set('type', strlen($parts[1]) === 12 ? 'individual' : 'legal');
                                                                $set('name', $parts[0]);
                                                                $set('inn', $parts[1]);
                                                                if (isset($parts[2])) {
                                                                    $set('address', $parts[2]);
                                                                }
                                                            }
                                                        }
                                                    })
                                                    ->allowHtml()
                                                    ->columnSpanFull()
                                                    ->reactive(),
                                            ])
                                            ->compact(),
                                        
                                        Forms\Components\Section::make('Данные заказчика')
                                            ->schema([
                                                Forms\Components\Select::make('type')
                                                    ->label('Тип')
                                                    ->options(Client::getTypes())
                                                    ->default('legal')
                                                    ->required(),
                                                
                                                Forms\Components\TextInput::make('name')
                                                    ->label('Название / ФИО')
                                                    ->required()
                                                    ->maxLength(255),
                                                
                                                Forms\Components\TextInput::make('inn')
                                                    ->label('ИНН')
                                                    ->maxLength(12)
                                                    ->unique(),
                                                
                                                Forms\Components\TextInput::make('address')
                                                    ->label('Адрес')
                                                    ->maxLength(255),
                                                
                                                Forms\Components\TextInput::make('phone')
                                                    ->label('Телефон')
                                                    ->tel()
                                                    ->mask('+7 (999) 999-99-99')
                                                    ->placeholder('+7 (___) ___-__-__')
                                                    ->maxLength(30)
                                                    ->nullable()
                                                    ->unique(ignoreRecord: true)
                                                    ->stripCharacters(['(', ')', '-', ' '])                            
                                                    ->validationMessages([
                                                        'unique' => 'Этот номер телефона уже используется'
                                                    ]),
                                                
                                                Forms\Components\TextInput::make('email')
                                                    ->label('Email')
                                                    ->email(),
                                            ])
                                            ->columns(2),
                                    ])
                                    ->action(function (array $data, $set) {
                                        unset($data['company_search']);
                                        $client = Client::create($data);
                                        
                                        $set('client_id', $client->id);
                                        
                                        Notification::make()
                                            ->title('✅ Заказчик создан и привязан')
                                            ->body($client->name)
                                            ->success()
                                            ->send();
                                    })
                            )
                            ->helperText('Выберите заказчика из списка или создайте нового')
                            ->columnSpanFull(),

                    ])->columns(4),
                
                // Forms\Components\Section::make('Финансовая информация')
                //     ->schema([
                        
                //         Forms\Components\TextInput::make('amount')
                //             ->label('Сумма Заявки')
                //             ->maxLength(255)
                //             ->helperText('Например: 10000ч, 50000ндс, 21600/72ндс')
                //             ->reactive()
                //             ->afterStateUpdated(function ($set, $state, $get) {
                //                 self::calculateFinancialFields($set, $get);
                //             }),
                                                
                //         Forms\Components\TextInput::make('actual_amount')
                //             ->label('Факт. сумма')
                //             ->numeric()
                //             ->prefix('₽')
                //             ->reactive()
                //             ->afterStateUpdated(function ($set, $state, $get) {
                //                 self::calculateFinancialFields($set, $get);
                //             }),

                //         Forms\Components\Select::make('payment_type')
                //             ->label('Тип оплаты')
                //             ->options([
                //                 'н' => 'н',
                //                 'ндс' => 'ндс', 
                //                 'бн' => 'бн',
                //             ])
                //             ->reactive() // Делаем поле реактивным
                //             ->afterStateUpdated(function ($set, $state, $get) {
                //                 // Пересчитываем при изменении типа оплаты
                //                 self::calculateFinancialFields($set, $get);
                //             }),
                        
                //         Forms\Components\TextInput::make('tech_amount')
                //             ->label('Сумма Ст. тех')
                //             ->numeric()
                //             ->prefix('₽')
                //             ->reactive()
                //             ->afterStateUpdated(function ($set, $state, $get) {
                //                 self::calculateFinancialFields($set, $get);
                //             }),
                        
                //         Forms\Components\TextInput::make('dispatcher_percent')
                //             ->label('% Диспетчера')
                //             ->numeric()
                //             ->suffix('%')
                //             ->reactive()
                //             ->afterStateUpdated(function ($set, $state, $get) {
                //                 self::calculateFinancialFields($set, $get);
                //             }),
                        
                //         // Вычисляемые поля (только для чтения)
                //         Forms\Components\TextInput::make('vat')
                //             ->label('НДС')
                //             ->numeric()
                //             ->prefix('₽')
                //             ->dehydrated(true), // Сохранить в БД
                            
                //         Forms\Components\TextInput::make('total')
                //             ->label('Итого')
                //             ->numeric()
                //             ->prefix('₽')
                //             ->dehydrated(true),
                            
                //         Forms\Components\TextInput::make('usn')
                //             ->label('УСН')
                //             ->numeric()
                //             ->prefix('₽')
                //             ->dehydrated(true),
                //     ])->columns(3),
                
                // Forms\Components\Section::make('Детали работы')
                //     ->schema([
                //         Forms\Components\Textarea::make('address')
                //             ->label('Адрес подачи')
                //             ->rows(2)
                //             ->columnSpanFull(),
                //         Forms\Components\TextInput::make('work_time')
                //             ->label('Время работы')
                //             ->placeholder('9-00 до 18-00')
                //             ->maxLength(255),
                            
                //         // Часы: для диспетчера и водителя
                //         Forms\Components\TextInput::make('hours_dispatcher')
                //             ->label('Час (диспетчер)')
                //             ->numeric()
                //             ->helperText('Часы для нас'),
                //         Forms\Components\TextInput::make('hours_driver')
                //             ->label('Час (водитель)')
                //             ->numeric()
                //             ->helperText('Часы для водителя'),
                            
                //         // Км: для диспетчера и водителя  
                //         Forms\Components\TextInput::make('km_dispatcher')
                //             ->label('Км (диспетчер)')
                //             ->numeric()
                //             ->helperText('Км для нас'),
                //         Forms\Components\TextInput::make('km_driver')
                //             ->label('Км (водитель)')
                //             ->numeric()
                //             ->helperText('Км для водителя'),
                            
                //         Forms\Components\TextInput::make('km_check')
                //             ->label('СВЕРКА КМ')
                //             ->numeric(),
                //     ])->columns(3),
                
                Forms\Components\Section::make('Документы и оплата')
                    ->schema([
                        Forms\Components\TextInput::make('invoice')
                            ->label('Счет')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('paid_status')
                            ->label('Оплачен')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('tech_payment')
                            ->label('Оплата ст.тех')
                            ->maxLength(255),
                    ])->columns(3),
                
                // Forms\Components\Section::make('Дополнительная информация')
                //     ->schema([
                //         Forms\Components\TextInput::make('reason')
                //             ->label('Причина отказа/переноса')
                //             ->maxLength(255),
                //         Forms\Components\Textarea::make('notes')
                //             ->label('Примечание')
                //             ->rows(3)
                //             ->columnSpanFull(),
                //     ]),

                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('notifyDriver')
                        ->label('📢 Отправить информацию водителю')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->action(function ($livewire) {
                            $trip = $livewire->record;
                            $service = app(\App\Services\TelegramNotificationService::class);
                            
                            // Теперь вызываем ОДИН метод для всех случаев
                            $result = $service->sendDriverNotification($trip);
                            
                            if ($result) {
                                $message = ($trip->status === 'Новая') 
                                    ? 'Новая заявка отправлена водителю. Напоминания запущены.' 
                                    : 'Обновленная информация отправлена водителю.';
                                    
                                \Filament\Notifications\Notification::make()
                                    ->title('✅ Успешно')
                                    ->body($message)
                                    ->success()
                                    ->send();
                            } else {
                                \Filament\Notifications\Notification::make()
                                    ->title('❌ Ошибка')
                                    ->body('Не удалось отправить уведомление')
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Отправка информации водителю')
                        ->modalDescription(function ($livewire) {
                            $trip = $livewire->record;
                            return ($trip->status === 'Новая') 
                                ? 'Отправить новую заявку водителю? Начнутся напоминания каждые 30 минут.'
                                : 'Отправить обновленную информацию по заявке водителю?';
                        })
                        ->modalSubmitActionLabel('Отправить')
                        ->hidden(fn ($get) => !$get('driver_id'))
                        ->visible(fn ($livewire) => 
                            $livewire instanceof Pages\EditTrip && 
                            $livewire->record->driver_id &&
                            auth()->user()->canEdit()
                        ),
                ])
                ->columnSpanFull() 
                ->alignEnd(), 
   
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(50)
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Номер заявки')
                    ->searchable()
                    ->sortable(),           
                Tables\Columns\TextColumn::make('date')
                    ->label('Дата')
                    ->date('d.m.Y') 
                    ->sortable(),
                Tables\Columns\TextColumn::make('type_t')
                    ->label('Тип техники')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Водитель')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('car_number')
                    ->label('№ авто')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dispatcher.name')
                    ->label('Диспетчер')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Новая'      => 'gray',     // Серый - ожидает действий
                        'В работе'   => 'warning',  // Оранжевый - в процессе
                        'Выполнена'  => 'success',  // Зеленый - завершено успешно
                        'Отменена'   => 'danger',   // Красный - отменено
                        'Перенесена' => 'info',     // Голубой - перенесено
                        'Отклонена'  => 'danger',   // Красный - отклонено водителем
                        'Ремонт'     => 'info',   // Ремонт авто
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('has_waybill')
                    ->label('Путевка')
                    ->boolean()
                    ->getStateUsing(function (Trip $record): bool {
                        // Проверяем есть ли связанные путевки через relation manager
                        return $record->waybills()->exists();
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('telegram_sent')
                    ->label('Отправлено')
                    ->boolean()
                    ->getStateUsing(function (Trip $record): bool {
                        return $record->telegram_sent;
                    })
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->tooltip(function (Trip $record): string {
                        if ($record->telegram_sent) {
                            if ($record->telegram_sent_at) {
                                // Конвертируем UTC в Москву (UTC+3)
                                $moscowTime = $record->telegram_sent_at
                                    ->setTimezone('Europe/Samara')
                                    ->format('d.m.Y H:i');
                                $count = $record->telegram_sent_count;
                                return "Отправлено {$count} раз. Последний: {$moscowTime}";
                            }
                            return "Уведомление отправлено (время не указано)";
                        }
                        return "Уведомление не отправлялось";
                    }),
                    
            ])
            // ->headerActions([
            //     ExportAction::make()
            //         ->exports([
            //             ExcelExport::make()
            //                 ->fromTable()
            //                 ->withFilename(fn () => 'заявки_' . now()->format('d-m-Y'))
            //                 ->modifyQueryUsing(fn ($query) => $query->with('driver'))
            //         ]),
            // ])
            ->filters([
                Tables\Filters\SelectFilter::make('driver')
                    ->label('Водитель')
                    ->relationship('driver', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('dispatcher')
                    ->label('Диспетчер')
                    ->relationship('dispatcher', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('type_t')
                    ->label('Тип техники')
                    ->options([
                        'Автовышка'        => 'Автовышка',    
                        'Машина прикрытия' => 'Машина прикрытия',
                    ])
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'Новая'      => 'Новая',    
                        'В работе'   => 'В работе',
                        'Выполнена'  => 'Выполнена', 
                        'Отменена'   => 'Отменена',
                        'Перенесена' => 'Перенесена', 
                        'Отклонена'  => 'Отклонена', 
                        'Ремонт'     => 'Ремонт', 
                    ]),
                    
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')
                            ->label('С даты'),
                        Forms\Components\DatePicker::make('date_until')
                            ->label('По дату'),
                    ])
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['date_from'] ?? null) {
                            $indicators[] = Indicator::make('С ' . Carbon::parse($data['date_from'])->format('d.m.Y'))
                                ->removeField('date_from');
                        }
                        if ($data['date_until'] ?? null) {
                            $indicators[] = Indicator::make('По ' . Carbon::parse($data['date_until'])->format('d.m.Y'))
                                ->removeField('date_until');
                        }
                        return $indicators;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '>=', $date),
                            )
                            ->when(
                                $data['date_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (): bool => auth()->user()->canEdit()),
                
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->isAdmin()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()->isAdmin()),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrips::route('/'),
            'create' => Pages\CreateTrip::route('/create'),
            'edit' => Pages\EditTrip::route('/{record}/edit'),
        ];
    }

    protected static function calculateFinancialFields(Set $set, $get): void
    {
        $paymentType = $get('payment_type');
        $actualAmount = floatval($get('actual_amount') ?? 0);
        $techAmount = floatval($get('tech_amount') ?? 0);
        $dispatcherPercent = floatval($get('dispatcher_percent') ?? 0);
        
        // Расчет НДС
        $vat = 0;
        if ($paymentType === 'ндс') {
            $vat = -($actualAmount * 20 / 120);
        }
        $set('vat', number_format($vat, 2, '.', ''));
        
        // Расчет УСН
        $usn = 0;
        if ($paymentType === 'бн' || $paymentType === 'ндс') {
            $usn = -($actualAmount * 0.07);
        }
        $set('usn', number_format($usn, 2, '.', ''));
        
        // Расчет ИТОГО
        $dispatcherAmount = $actualAmount * ($dispatcherPercent / 100);
        $total = $actualAmount + $techAmount + $dispatcherAmount + $vat;
        $set('total', number_format($total, 2, '.', ''));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\WaybillsRelationManager::class,
        ];
    }
}