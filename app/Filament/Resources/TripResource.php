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
                        Forms\Components\TextInput::make('name')
                            ->label('Название заявки')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('date')
                            ->label('Дата')
                            ->required()
                            ->native(false),
                        Forms\Components\TimePicker::make('time')
                            ->label('Время')
                            ->seconds(false),
                        Forms\Components\TextInput::make('dispatcher')
                            ->label('Диспетчер')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('client_name')
                            ->label('Наименование клиента')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('client_type')
                            ->label('Тип клиента')
                            ->options([
                                'П' => 'П',
                                'Н' => 'Н',
                            ])
                            ->default('П'),
                        Forms\Components\Select::make('status')
                            ->label('Статус заявки')
                            ->options([
                                'Новая'      => 'Новая',    
                                'В работе'   => 'В работе',
                                'Выполнена'  => 'Выполнена', 
                                'Отменена'   => 'Отменена',
                                'Перенесена' => 'Перенесена', 
                                'Отклонена'  => 'Отклонена', 
                            ])
                            ->default('Новая'),
                    ])->columns(2),
                
                Forms\Components\Section::make('Техническая информация')
                    ->schema([
                        Forms\Components\Toggle::make('has_waybill')
                            ->label('Путевка')
                            ->default(false),
                        Forms\Components\TextInput::make('height')
                            ->label('Высота')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('car_number')
                            ->label('№ авто')
                            ->maxLength(255),
                        Forms\Components\Select::make('driver_id')
                            ->label('Водитель')
                            ->relationship('driver', 'name')
                            ->searchable()
                            ->preload(),
                    ])->columns(4),
                
                Forms\Components\Section::make('Финансовая информация')
                    ->schema([
                        Forms\Components\Select::make('payment_type')
                            ->label('Тип оплаты')
                            ->options([
                                'н' => 'н',
                                'ндс' => 'ндс', 
                                'бн' => 'бн',
                            ])
                            ->reactive() // Делаем поле реактивным
                            ->afterStateUpdated(function ($set, $state, $get) {
                                // Пересчитываем при изменении типа оплаты
                                self::calculateFinancialFields($set, $get);
                            }),
                        
                        Forms\Components\TextInput::make('amount')
                            ->label('Сумма Заявки')
                            ->maxLength(255)
                            ->helperText('Например: 10000ч, 50000ндс, 21600/72ндс')
                            ->reactive()
                            ->afterStateUpdated(function ($set, $state, $get) {
                                self::calculateFinancialFields($set, $get);
                            }),
                                                
                        Forms\Components\TextInput::make('actual_amount')
                            ->label('Факт. сумма')
                            ->numeric()
                            ->prefix('₽')
                            ->reactive()
                            ->afterStateUpdated(function ($set, $state, $get) {
                                self::calculateFinancialFields($set, $get);
                            }),
                        
                        Forms\Components\TextInput::make('tech_amount')
                            ->label('Сумма Ст. тех')
                            ->numeric()
                            ->prefix('₽')
                            ->reactive()
                            ->afterStateUpdated(function ($set, $state, $get) {
                                self::calculateFinancialFields($set, $get);
                            }),
                        
                        Forms\Components\TextInput::make('dispatcher_percent')
                            ->label('% Диспетчера')
                            ->numeric()
                            ->suffix('%')
                            ->reactive()
                            ->afterStateUpdated(function ($set, $state, $get) {
                                self::calculateFinancialFields($set, $get);
                            }),
                        
                        // Вычисляемые поля (только для чтения)
                        Forms\Components\TextInput::make('vat')
                            ->label('НДС')
                            ->numeric()
                            ->prefix('₽')
                            ->dehydrated(true), // Сохранить в БД
                            
                        Forms\Components\TextInput::make('total')
                            ->label('Итого')
                            ->numeric()
                            ->prefix('₽')
                            ->dehydrated(true),
                            
                        Forms\Components\TextInput::make('usn')
                            ->label('УСН')
                            ->numeric()
                            ->prefix('₽')
                            ->dehydrated(true),
                    ])->columns(3),
                
                Forms\Components\Section::make('Детали работы')
                    ->schema([
                        Forms\Components\Textarea::make('address')
                            ->label('Адрес подачи')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('work_time')
                            ->label('Время работы')
                            ->placeholder('9-00 до 18-00')
                            ->maxLength(255),
                            
                        // Часы: для диспетчера и водителя
                        Forms\Components\TextInput::make('hours_dispatcher')
                            ->label('Час (диспетчер)')
                            ->numeric()
                            ->helperText('Часы для нас'),
                        Forms\Components\TextInput::make('hours_driver')
                            ->label('Час (водитель)')
                            ->numeric()
                            ->helperText('Часы для водителя'),
                            
                        // Км: для диспетчера и водителя  
                        Forms\Components\TextInput::make('km_dispatcher')
                            ->label('Км (диспетчер)')
                            ->numeric()
                            ->helperText('Км для нас'),
                        Forms\Components\TextInput::make('km_driver')
                            ->label('Км (водитель)')
                            ->numeric()
                            ->helperText('Км для водителя'),
                            
                        Forms\Components\TextInput::make('km_check')
                            ->label('СВЕРКА КМ')
                            ->numeric(),
                    ])->columns(3),
                
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
                
                Forms\Components\Section::make('Дополнительная информация')
                    ->schema([
                        Forms\Components\TextInput::make('reason')
                            ->label('Причина отказа/переноса')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('notes')
                            ->label('Примечание')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date')
                    ->label('Дата')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('client_name')
                    ->label('Клиент')
                    ->searchable(),
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Водитель')
                    ->searchable(),
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
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        // Если это число - форматируем как деньги, иначе оставляем как есть
                        if (is_numeric($state)) {
                            return number_format($state, 0, '', ' ') . ' ₽';
                        }
                        return $state; // Оставляем текстовый формат "10000ч", "50000ндс"
                    }),
                Tables\Columns\IconColumn::make('has_waybill')
                    ->label('Путевка')
                    ->boolean(),
                Tables\Columns\TextColumn::make('car_number')
                    ->label('№ авто')
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('driver')
                    ->label('Водитель')
                    ->relationship('driver', 'name')
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
                Tables\Actions\EditAction::make(),
                
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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