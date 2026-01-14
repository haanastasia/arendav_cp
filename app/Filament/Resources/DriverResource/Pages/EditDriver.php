<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => auth()->user()->canEdit()),
        ];
    }

    protected function getFormActions(): array
    {
        if (!auth()->user()->canEdit()) {
            return [];
        }
        
        return [
            $this->getSaveFormAction(),
            //$this->getSaveAndStayFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    public function mount($record): void
    {
        parent::mount($record);
        
        if (!auth()->user()->canEdit()) {
            $this->form->disabled();
        }
    }
}
