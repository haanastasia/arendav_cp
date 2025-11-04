<?php

namespace App\Filament\Widgets;

use App\Models\Trip;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LatestTrips extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Всего заявок', Trip::count())
                ->icon('heroicon-o-document-text')
                ->color('primary'),
                
            Stat::make('Свободные', Trip::where('status', 'Новые')->count())
                ->icon('heroicon-o-queue-list')
                ->color('warning'),

            Stat::make('В работе', Trip::where('status', 'В работе')->count())
                ->icon('heroicon-o-clock')
                ->color('warning'),
                
            Stat::make('Выполнено', Trip::where('status', 'Выполнена')->count())
                ->icon('heroicon-o-check-badge')
                ->color('success'),
                
            // Stat::make('Отменено', Trip::where('status', 'Отменена')->count())
            //     ->icon('heroicon-o-x-circle')
            //     ->color('danger'),
        ];
    }
}