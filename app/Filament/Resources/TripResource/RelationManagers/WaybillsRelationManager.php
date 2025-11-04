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
                Forms\Components\TextInput::make('file_size')
                    ->label('Размер')
                    ->suffix(' bytes'),
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
                Tables\Columns\TextColumn::make('file_name')
                    ->label('Файл')
                    ->formatStateUsing(function ($state, $record) {
                        $extension = pathinfo($state, PATHINFO_EXTENSION);
                        return match($extension) {
                            'jpg', 'jpeg', 'png' => '🖼️ Фото',
                            'pdf' => '📄 PDF документ',
                            'xlsx', 'xls' => '📊 Excel файл',
                            'doc', 'docx' => '📝 Word документ',
                            default => '📎 Файл'
                        };
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('file_size')
                    ->label('Размер')
                    ->formatStateUsing(fn ($state) => number_format($state / 1024, 1) . ' KB'),
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
                        \Log::info('Generated download URL', [
                            'file_path' => $record->file_path,
                            'generated_url' => $url
                        ]);
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