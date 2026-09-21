<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Select::make('role')
                    ->options([
                        UserRole::ADMIN->value => 'Admin',
                        UserRole::SALES->value => 'Sales',
                    ])
                    ->required()
                    ->default(UserRole::SALES->value),
                Toggle::make('is_active')
                    ->label('Active Account')
                    ->default(true)
                    ->visible(fn (): bool => (bool) Auth::user()?->isAdmin())
                    ->disabled(fn (?User $record): bool => $record?->id === Auth::id())
                    ->helperText(fn (?User $record): ?string => $record?->id === Auth::id() ? 'You cannot deactivate your own account.' : null),
                TextInput::make('password')
                    ->password()
                    ->nullable()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->rules(['regex:/[A-Z]/', 'regex:/[0-9]/'])
                    ->maxLength(255)
                    ->same('password_confirmation'),
                TextInput::make('password_confirmation')
                    ->password()
                    ->label('Confirm Password')
                    ->nullable()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->dehydrated(false),
            ]);
    }
}
