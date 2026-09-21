<?php

namespace App\Filament\Resources\CompanySettings\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanySettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company_name')
                    ->label('Company Name')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('gstin')
                    ->label('GSTIN')
                    ->searchable(),
                TextColumn::make('state')
                    ->label('Registered State'),
                TextColumn::make('bank_name')
                    ->label('Bank & Branch'),
                TextColumn::make('bank_account_number')
                    ->label('Account Number'),
                TextColumn::make('bank_ifsc')
                    ->label('IFSC'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
