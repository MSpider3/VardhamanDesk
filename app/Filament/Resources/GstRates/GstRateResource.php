<?php

namespace App\Filament\Resources\GstRates;

use App\Filament\Resources\GstRates\Pages\CreateGstRate;
use App\Filament\Resources\GstRates\Pages\EditGstRate;
use App\Filament\Resources\GstRates\Pages\ListGstRates;
use App\Filament\Resources\GstRates\Schemas\GstRateForm;
use App\Filament\Resources\GstRates\Tables\GstRatesTable;
use App\Models\GstRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class GstRateResource extends Resource
{
    protected static ?string $model = GstRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return GstRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GstRatesTable::configure($table);
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
            'index' => ListGstRates::route('/'),
            'create' => CreateGstRate::route('/create'),
            'edit' => EditGstRate::route('/{record}/edit'),
        ];
    }
}
