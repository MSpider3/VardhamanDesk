<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\IndianState;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\GstRate;
use App\Models\Invoice;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Overview')
                    ->columns(3)
                    ->schema([
                        Placeholder::make('display_number')
                            ->label('Invoice Number')
                            ->content(fn (?Invoice $record) => $record?->display_number ?? 'DRAFT (Auto-allocated on Send)'),

                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (?Invoice $record) => ($record?->status instanceof InvoiceStatus ? $record->status->label() : ($record?->status ?? 'Draft'))),

                        Select::make('client_id')
                            ->label('Client')
                            ->relationship('client', 'name', modifyQueryUsing: function ($query) {
                                if (! Auth::user()?->isAdmin()) {
                                    $query->where('assigned_to', Auth::id());
                                }
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->disabled(fn (?Invoice $record) => $record && ($record->status !== InvoiceStatus::DRAFT && $record->status !== InvoiceStatus::DRAFT->value))
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $client = Client::find($state);
                                    if ($client && $client->state) {
                                        $stateVal = $client->state instanceof IndianState ? $client->state->value : $client->state;
                                        $set('place_of_supply', $stateVal);
                                    }
                                }
                            }),

                        Select::make('place_of_supply')
                            ->label('Place of Supply')
                            ->options(collect(IndianState::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                            ->required()
                            ->live()
                            ->disabled(fn (?Invoice $record) => $record && ($record->status !== InvoiceStatus::DRAFT && $record->status !== InvoiceStatus::DRAFT->value)),

                        DatePicker::make('invoice_date')
                            ->label('Invoice Date')
                            ->default(now()->toDateString())
                            ->required()
                            ->live()
                            ->disabled(fn (?Invoice $record) => $record && ($record->status !== InvoiceStatus::DRAFT && $record->status !== InvoiceStatus::DRAFT->value))
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                if ($state && empty($get('due_date'))) {
                                    $set('due_date', Carbon::parse($state)->addDays(30)->format('Y-m-d'));
                                }
                            }),

                        DatePicker::make('due_date')
                            ->label('Due Date')
                            ->default(now()->addDays(30)->toDateString())
                            ->required()
                            ->disabled(fn (?Invoice $record) => $record && ($record->status !== InvoiceStatus::DRAFT && $record->status !== InvoiceStatus::DRAFT->value)),
                    ]),

                Section::make('Line Items')
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items')
                            ->columns(12)
                            ->disabled(fn (?Invoice $record) => $record && ($record->status !== InvoiceStatus::DRAFT && $record->status !== InvoiceStatus::DRAFT->value))
                            ->schema([
                                TextInput::make('description')
                                    ->label('Item Description')
                                    ->required()
                                    ->columnSpan(4),

                                TextInput::make('sac_code')
                                    ->label('SAC Code')
                                    ->required()
                                    ->default('998313')
                                    ->columnSpan(2),

                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(2),

                                TextInput::make('rate')
                                    ->label('Unit Rate (₹)')
                                    ->numeric()
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(2),

                                Select::make('gst_rate_id')
                                    ->label('GST Slab')
                                    ->options(GstRate::query()->where('is_active', true)->pluck('label', 'id'))
                                    ->required()
                                    ->live()
                                    ->columnSpan(2),
                            ]),
                    ]),

                Section::make('Tax & Amount Summary')
                    ->columns(3)
                    ->schema([
                        Placeholder::make('tax_type_preview')
                            ->label('Tax Classification')
                            ->content(function (callable $get) {
                                $pos = $get('place_of_supply');
                                if (! $pos) {
                                    return 'Select client / place of supply';
                                }

                                $company = CompanySetting::current();
                                $supplierCode = $company?->state_code
                                    ?? ($company ? IndianState::fromCodeOrName($company->state)?->code() : null)
                                    ?? '08';

                                $posCode = IndianState::fromCodeOrName($pos)?->code() ?? (string) $pos;

                                if ($posCode === $supplierCode) {
                                    return 'Intra-State (CGST + SGST split evenly)';
                                }

                                return 'Inter-State (IGST applicable)';
                            }),

                        Placeholder::make('subtotal_preview')
                            ->label('Subtotal (₹)')
                            ->content(function (callable $get, ?Invoice $record) {
                                return number_format((float) ($record?->subtotal ?? 0), 2);
                            }),

                        Placeholder::make('total_preview')
                            ->label('Grand Total (₹)')
                            ->content(function (callable $get, ?Invoice $record) {
                                return number_format((float) ($record?->total ?? 0), 2);
                            }),
                    ]),
            ]);
    }
}
