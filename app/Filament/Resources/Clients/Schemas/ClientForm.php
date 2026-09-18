<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\IndianState;
use App\Models\Client;
use App\Rules\ValidGstin;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('lead_info')
                    ->label('Originating Lead')
                    ->content(fn (?Client $record) => $record?->lead ? "{$record->lead->name} ({$record->lead->company})" : 'Direct Client (No Lead)')
                    ->visible(fn (?Client $record) => filled($record?->lead_id)),

                TextInput::make('name')
                    ->label('Client Name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('company')
                    ->label('Company Name')
                    ->maxLength(255),

                TextInput::make('phone')
                    ->tel()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email Address')
                    ->email()
                    ->maxLength(255),

                Textarea::make('billing_address')
                    ->label('Billing Address')
                    ->required()
                    ->rows(3),

                Select::make('state')
                    ->label('State / UT')
                    ->options(collect(IndianState::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                    ->required()
                    ->live(),

                TextInput::make('gstin')
                    ->label('GSTIN (Optional)')
                    ->placeholder('e.g. 08AAAAA0000A1Z5')
                    ->maxLength(15)
                    ->rule(fn ($get) => new ValidGstin($get('state'))),

                Select::make('assigned_to')
                    ->label('Assigned Sales Owner')
                    ->relationship('assignedUser', 'name')
                    ->default(fn () => Auth::id())
                    ->required()
                    ->visible(fn () => Auth::user()?->isAdmin() ?? false)
                    ->disabled(fn () => ! (Auth::user()?->isAdmin() ?? false))
                    ->dehydrated(fn () => Auth::user()?->isAdmin() ?? false),
            ]);
    }
}
