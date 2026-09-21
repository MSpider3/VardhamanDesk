<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Services\InvoiceSendService;
use App\Services\RecordPayment;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('invoices.*')
                ->selectRaw('COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.invoice_id = invoices.id), 0) as paid_amount')
                ->selectRaw('(invoices.total - COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.invoice_id = invoices.id), 0)) as outstanding_amount')
            )
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

                TextColumn::make('paid_amount')
                    ->label('Paid (₹)')
                    ->money('INR')
                    ->sortable()
                    ->color('success'),

                TextColumn::make('outstanding_amount')
                    ->label('Balance (₹)')
                    ->money('INR')
                    ->sortable()
                    ->color(fn (Invoice $record) => (float) $record->outstanding_amount > 0 ? 'warning' : 'gray'),
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

                Action::make('record_payment')
                    ->label('Record Payment')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->color('success')
                    ->visible(fn (Invoice $record) => in_array($record->status, [InvoiceStatus::SENT, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::SENT->value, InvoiceStatus::PARTIALLY_PAID->value], true) && (float) $record->outstanding_amount > 0)
                    ->form([
                        Placeholder::make('summary')
                            ->label('Balance Summary')
                            ->content(fn (Invoice $record) => sprintf(
                                'Invoice Total: ₹%s | Already Paid: ₹%s | Remaining Balance: ₹%s',
                                number_format((float) $record->total, 2),
                                number_format((float) $record->paid_amount, 2),
                                number_format((float) $record->outstanding_amount, 2)
                            )),

                        TextInput::make('amount')
                            ->label('Payment Amount (₹)')
                            ->numeric()
                            ->default(fn (Invoice $record) => (float) $record->outstanding_amount)
                            ->minValue(0.01)
                            ->maxValue(fn (Invoice $record) => (float) $record->outstanding_amount)
                            ->required(),

                        Select::make('method')
                            ->label('Payment Method')
                            ->options(collect(PaymentMethod::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                            ->default(PaymentMethod::BANK_TRANSFER->value)
                            ->required(),

                        DatePicker::make('payment_date')
                            ->label('Payment Date')
                            ->default(now()->toDateString())
                            ->required(),

                        TextInput::make('reference_note')
                            ->label('Reference / Transaction Note (Optional)')
                            ->placeholder('e.g. UTR12345678, Chq #00124')
                            ->maxLength(100)
                            ->rules(['nullable', 'string', 'regex:/^[A-Za-z0-9\/\-\s#.,:@_()]+$/']),
                    ])
                    ->action(function (Invoice $record, array $data, RecordPayment $service) {
                        try {
                            $service->execute($record, $data, Auth::user());
                            Notification::make()
                                ->title('Payment Recorded Successfully')
                                ->body('Invoice balance and status updated.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Failed to Record Payment')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('download_pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->url(fn (Invoice $record) => route('invoices.pdf', $record))
                    ->openUrlInNewTab(),

                EditAction::make()
                    ->visible(fn (Invoice $record) => ($record->status instanceof InvoiceStatus ? $record->status === InvoiceStatus::DRAFT : $record->status === InvoiceStatus::DRAFT->value)),

                DeleteAction::make()
                    ->visible(fn (Invoice $record) => ($record->status instanceof InvoiceStatus ? $record->status === InvoiceStatus::DRAFT : $record->status === InvoiceStatus::DRAFT->value)),
            ]);
    }
}
