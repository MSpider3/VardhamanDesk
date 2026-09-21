<?php

namespace App\Filament\Resources\GstRates\Pages;

use App\Filament\Resources\GstRates\GstRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGstRates extends ListRecords
{
    protected static string $resource = GstRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
