<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use App\Filament\Resources\DriverResource\RelationManagers;
use App\Models\Driver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static ?int $navigationSort = 20;
    protected static ?string $navigationIcon = 'heroicon-o-user'; // Иконка в меню
    protected static ?string $navigationLabel = 'Водители'; // Название в меню
    protected static ?string $modelLabel = 'Водитель'; // Название в единственном числе
    protected static ?string $pluralModelLabel = 'Водители'; // Название во множественном числе

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Имя и фамилия')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->maxLength(255),
                Forms\Components\TextInput::make('telegram_username')
                    ->label('Telegram @username')
                    ->prefix('@')
                    ->maxLength(255)
                    ->helperText('Укажите @username водителя в Telegram'),
            Forms\Components\TextInput::make('telegram_chat_id')
                ->label('Telegram Chat ID')
                ->numeric()
                ->helperText('Заполнится автоматически при регистрации в боте')
                ->disabled(), // Только для чтения
                Forms\Components\Textarea::make('comment')
                    ->label('Комментарий')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя и фамилия')
                    ->searchable()
                    ->sortable()
                    ->weight('bold') // Жирный шрифт для имени
                    ->color(fn (Driver $record) => $record->telegram_chat_id ? 'gray' : 'danger'), // Цвет в зависимости от регистрации
                    
                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('telegram_username')
                    ->label('Telegram')
                    ->formatStateUsing(fn ($state) => $state ? '@'.$state : '—')
                    ->searchable()
                    ->color(fn (Driver $record) => $record->telegram_chat_id ? 'success' : 'gray'),
                    
                Tables\Columns\TextColumn::make('telegram_chat_id')
                    ->label('Статус в боте')
                    ->formatStateUsing(function ($state, Driver $record) {
                        if ($record->telegram_chat_id) {
                            return '✅ Зарегистрирован';
                        }
                        return '❌ Не в боте';
                    })
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
            ])
            ->filters([
                // Фильтр по статусу регистрации
                Tables\Filters\Filter::make('has_telegram')
                    ->label('Только зарегистрированные в боте')
                    ->query(fn ($query) => $query->whereNotNull('telegram_chat_id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            // Подсветка строк для незарегистрированных водителей
            ->recordClasses(fn (Driver $record) => $record->telegram_chat_id ? null : 'bg-danger-50 dark:bg-danger-950/20');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}