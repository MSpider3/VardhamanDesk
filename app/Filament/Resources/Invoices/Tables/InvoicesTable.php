<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\InvoiceSendService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->formatStateUsing(fn (Invoice $record) => $record->display_number)
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (InvoiceStatus|string $state): string => match ($state instanceof InvoiceStatus ? $state : InvoiceStatus::tryFrom($state)) {
                        InvoiceStatus::DRAFT => 'gray',
                        InvoiceStatus::SENT => 'info',
                        InvoiceStatus::PARTIALLY_PAID => 'warning',
                        InvoiceStatus::PAID => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof InvoiceStatus ? $state->label() : (InvoiceStatus::tryFrom($state)?->label() ?? $state)),

                TextColumn::make('place_of_supply')
                    ->label('Place of Supply')
                    ->formatStateUsing(fn ($state) => IndianState::fromCodeOrName($state)?->stateName() ?? $state)
                    ->toggleable(),

                TextColumn::make('invoice_date')
                    ->label('Invoice Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('subtotal')
                    ->label('Taxable (₹)')
                    ->money('INR')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Total (₹)')
                    ->money('INR')
                    ->sortable()
                    ->weight('bold'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class),
            ])
            ->recordActions([
                Action::make('send')
                    ->label('Send')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Finalize and Send Invoice')
                    ->modalDescription('Are you sure you want to send this draft invoice? An official sequential invoice number will be permanently allocated and all line items will be locked.')
                    ->visible(fn (Invoice $record) => ($record->status instanceof InvoiceStatus ? $record->status === InvoiceStatus::DRAFT : $record->status === InvoiceStatus::DRAFT->value))
                    ->action(function (Invoice $record, InvoiceSendService $sendService) {
                        try {
                            $sentInvoice = $sendService->send($record);
                            Notification::make()
                                ->title('Invoice Sent Successfully')
                                ->body("Allocated Number: {$sentInvoice->invoice_number}")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Failed to Send Invoice')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make()
                    ->visible(fn (Invoice $record) => ($record->status instanceof InvoiceStatus ? $record->status === InvoiceStatus::DRAFT : $record->status === InvoiceStatus::DRAFT->value)),

                DeleteAction::make()
                    ->visible(fn (Invoice $record) => ($record->status instanceof InvoiceStatus ? $record->status === InvoiceStatus::DRAFT : $record->status === InvoiceStatus::DRAFT->value)),
            ]);
    }
}
