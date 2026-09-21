<?php

namespace App\Filament\Resources\GstRates\Schemas;

use App\Models\GstRate;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class GstRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('rate')
                    ->label('Rate (%)')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->disabled(fn (?GstRate $record) => $record?->invoiceItems()->exists() ?? false)
                    ->helperText(fn (?GstRate $record) => $record?->invoiceItems()->exists()
                        ? 'Rate percentage is locked because it is used by existing invoice items. To change rates, create a new GST rate and deactivate this one.'
                        : null),
                TextInput::make('label')
                    ->label('Label')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. 18% GST'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
