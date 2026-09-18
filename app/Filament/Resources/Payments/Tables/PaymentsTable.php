<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('payment_date', 'desc')
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice #')
                    ->formatStateUsing(fn (Payment $record) => $record->invoice?->display_number ?? '-')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('invoice.client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount (₹)')
                    ->money('INR')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof PaymentMethod ? $state->label() : (PaymentMethod::tryFrom($state)?->label() ?? $state)),

                TextColumn::make('reference_note')
                    ->label('Reference Note')
                    ->placeholder('None')
                    ->searchable(),

                TextColumn::make('recordedBy.name')
                    ->label('Recorded By')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('method')
                    ->options(collect(PaymentMethod::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
