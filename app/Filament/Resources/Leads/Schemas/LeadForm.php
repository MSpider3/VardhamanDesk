<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Contact Person')
                    ->required()
                    ->maxLength(255),
                TextInput::make('company')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->tel()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email Address')
                    ->email()
                    ->maxLength(255),
                Select::make('source')
                    ->options(LeadSource::class)
                    ->required(),
                Select::make('assigned_to')
                    ->label('Assigned To')
                    ->relationship('assignedTo', 'name')
                    ->default(fn () => Auth::id())
                    ->required()
                    ->visible(fn () => Auth::user()?->isAdmin() ?? false)
                    ->disabled(fn () => ! (Auth::user()?->isAdmin() ?? false))
                    ->dehydrated(fn () => Auth::user()?->isAdmin() ?? false),
                Select::make('status')
                    ->options(LeadStatus::class)
                    ->default(LeadStatus::NEW->value)
                    ->required()
                    ->disabled(fn (?Lead $record) => $record?->status === LeadStatus::CONVERTED),
                DatePicker::make('next_follow_up_date')
                    ->label('Next Follow-Up Date'),
            ]);
    }
}
