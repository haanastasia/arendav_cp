<?php

namespace App\Filament\Resources\TripResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;

class WaybillsRelationManager extends RelationManager
{
    protected static string $relationship = 'waybills';
    protected static ?string $title = 'Путевые листы';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('original_name')
                    ->label('Имя файла')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('uploaded_at')
                    ->label('Загружен')
                    ->formatStateUsing(fn ($state) => $state?->format('d.m.Y H:i')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_name') // ← меняем на file_name
            ->columns([
                Tables\Columns\ImageColumn::make('file_path')
                    ->label('Файл')
                    ->getStateUsing(function ($record) {
                        // Проверяем, является ли файл изображением
                        $extension = pathinfo($record->file_name, PATHINFO_EXTENSION);
                        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                        
                        if (in_array(strtolower($extension), $imageExtensions)) {
                            return asset('storage/' . $record->file_path);
                        }
                    
                        return null;
                    })
                    ->defaultImageUrl(function ($record) {
                        // Иконки для разных типов файлов
                        $extension = pathinfo($record->file_name, PATHINFO_EXTENSION);
                        
                        return match(strtolower($extension)) {
                            'pdf' => 'https://cdn-icons-png.flaticon.com/512/337/337946.png',
                            'xlsx', 'xls' => 'https://cdn-icons-png.flaticon.com/512/732/732220.png',
                            'doc', 'docx' => 'https://cdn-icons-png.flaticon.com/512/732/732222.png',
                            'txt' => 'https://cdn-icons-png.flaticon.com/512/8242/8242936.png',
                            'zip', 'rar' => 'https://cdn-icons-png.flaticon.com/512/136/136526.png',
                            default => 'https://cdn-icons-png.flaticon.com/512/136/136521.png', // общая иконка файла
                        };
                    })
                    ->extraImgAttributes(['class' => 'rounded-lg shadow-sm'])
                    ->width(550)  
                    ->height('auto')  
                    ->square(false)  
                    ->circular(false)  
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('uploaded_at')
                    ->label('Загружен')
                    ->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Водитель'),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Скачать')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(function ($record) {
                        // Принудительно генерируем правильный URL
                        $url = asset('storage/' . $record->file_path);
                        return $url;
                    })
                    ->openUrlInNewTab(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Нет путевых листов')
            ->emptyStateDescription('Путевые листы, отправленные через Telegram, появятся здесь.');
    }
}