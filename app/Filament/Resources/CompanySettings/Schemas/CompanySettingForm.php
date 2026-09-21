<?php

namespace App\Filament\Resources\CompanySettings\Schemas;

use App\Enums\IndianState;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CompanySettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('company_name')
                    ->label('Company Name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('address')
                    ->label('Registered Address')
                    ->required()
                    ->rows(3),
                TextInput::make('gstin')
                    ->label('GSTIN')
                    ->required()
                    ->length(15)
                    ->regex('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'),
                TextInput::make('pan')
                    ->label('PAN')
                    ->required()
                    ->length(10)
                    ->regex('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'),
                Select::make('state_code')
                    ->label('State')
                    ->options(collect(IndianState::cases())->mapWithKeys(fn ($state) => [$state->value => "{$state->value} - {$state->stateName()}"]))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($enum = IndianState::fromCodeOrName($state)) {
                            $set('state', $enum->stateName());
                        }
                    }),
                TextInput::make('state')
                    ->label('State Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('logo_path')
                    ->label('Logo File Path')
                    ->maxLength(255),
                TextInput::make('bank_account_name')
                    ->label('Bank Account Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('bank_account_number')
                    ->label('Bank Account Number')
                    ->required()
                    ->maxLength(255),
                TextInput::make('bank_ifsc')
                    ->label('Bank IFSC Code')
                    ->required()
                    ->maxLength(255),
                TextInput::make('bank_name')
                    ->label('Bank Name & Branch')
                    ->required()
                    ->maxLength(255),
                TextInput::make('authorised_signatory_name')
                    ->label('Authorized Signatory Name')
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
