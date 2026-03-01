<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use MoveMoveIo\DaData\Enums\CompanyStatus;
use MoveMoveIo\DaData\Enums\CompanyType;
use MoveMoveIo\DaData\Facades\DaDataCompany;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationLabel = 'Заказчики';
    protected static ?string $modelLabel = 'Заказчик';
    protected static ?string $pluralModelLabel = 'Заказчики';
    protected static ?string $navigationGroup = 'Справочники';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Поиск организации
                Forms\Components\Select::make('company_search')
                    ->label('Поиск организации по названию или ИНН для автозаполнения')
                    ->searchable()
                    ->placeholder('Начните вводить название или ИНН организации')
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
                                $set('name', $parts[0]);     // Название
                                $set('inn', $parts[1]);      // ИНН
                                if (isset($parts[2])) {
                                    $set('address', $parts[2]); // Адрес
                                }
                                
                                // Определяем тип по длине ИНН
                                $inn = $parts[1];
                                if (strlen($inn) === 12) {
                                    $set('type', 'individual');
                                } else {
                                    $set('type', 'legal');
                                }
                                
                                // Показываем уведомление
                                \Filament\Notifications\Notification::make()
                                    ->title('Данные загружены')
                                    ->body($parts[0])
                                    ->success()
                                    ->send();
                            }
                        }
                    })
                    ->allowHtml()
                    ->columnSpanFull()
                    ->reactive(),
                
                // Основная информация в секции с фоном
                Forms\Components\Section::make('Основная информация')
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
                            ->unique(ignoreRecord: true),
                        
                        Forms\Components\TextInput::make('address')
                            ->label('Адрес')
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                
                // Контакты
                Forms\Components\Section::make('Контакты')
                    ->schema([
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
                
                // Дополнительно
                Forms\Components\Section::make('Дополнительно')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                'active' => 'Активный',
                                'inactive' => 'Неактивный',
                            ])
                            ->default('active')
                            ->required(),
                        
                        // Forms\Components\Textarea::make('comment')
                        //     ->label('Комментарий')
                        //     ->rows(3),
                        
                        Forms\Components\Hidden::make('client_data'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\IconColumn::make('type')
                    ->label('Тип')
                    ->icon(fn (string $state): string => match ($state) {
                        'legal' => 'heroicon-o-building-office',
                        'individual' => 'heroicon-o-user',
                    }),
                    
                Tables\Columns\TextColumn::make('inn')
                    ->label('ИНН')
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон'),
                    
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Статус')
                    ->colors([
                        'success' => 'active',
                        'gray' => 'inactive',
                    ])
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? 'Активный' : 'Неактивный'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'legal' => 'Юрлица',
                        'individual' => 'Физлица',
                    ]),
                //Tables\Filters\TrashedFilter::make(),
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
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
    
 
}