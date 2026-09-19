<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Services\RecordPayment;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payment Ledger';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Invoice
            && ($ownerRecord->status !== InvoiceStatus::DRAFT && $ownerRecord->status !== InvoiceStatus::DRAFT->value);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        /** @var Invoice $invoice */
        $invoice = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('reference_note')
            ->defaultSort('payment_date', 'desc')
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount (₹)')
                    ->money('INR')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof PaymentMethod ? $state->label() : (PaymentMethod::tryFrom($state)?->label() ?? $state)),

                TextColumn::make('reference_note')
                    ->label('Reference / Note')
                    ->placeholder('None')
                    ->searchable(),

                TextColumn::make('recordedBy.name')
                    ->label('Recorded By')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('record_payment')
                    ->label('Record Payment')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->color('success')
                    ->visible(fn () => in_array($invoice->status, [InvoiceStatus::SENT, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::SENT->value, InvoiceStatus::PARTIALLY_PAID->value], true) && (float) $invoice->outstanding_amount > 0)
                    ->form([
                        Placeholder::make('summary')
                            ->label('Balance Summary')
                            ->content(fn () => sprintf(
                                'Invoice Total: ₹%s | Paid: ₹%s | Remaining Balance: ₹%s',
                                number_format((float) $invoice->total, 2),
                                number_format((float) $invoice->paid_amount, 2),
                                number_format((float) $invoice->outstanding_amount, 2)
                            )),

                        TextInput::make('amount')
                            ->label('Payment Amount (₹)')
                            ->numeric()
                            ->default(fn () => (float) $invoice->outstanding_amount)
                            ->minValue(0.01)
                            ->maxValue(fn () => (float) $invoice->outstanding_amount)
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
                            ->rules(['nullable', 'regex:/^[A-Za-z0-9\/\-\s#.,:]+$/']),
                    ])
                    ->action(function (array $data, RecordPayment $service) use ($invoice) {
                        try {
                            $service->execute($invoice, $data, Auth::user());
                            Notification::make()
                                ->title('Payment Recorded Successfully')
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
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
