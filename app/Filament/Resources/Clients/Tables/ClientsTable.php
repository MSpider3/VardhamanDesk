<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Enums\IndianState;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Client Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('company')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->searchable(),

                TextColumn::make('email')
                    ->searchable(),

                TextColumn::make('state')
                    ->label('State')
                    ->formatStateUsing(fn ($state) => $state instanceof IndianState ? $state->stateName() : (IndianState::fromCodeOrName($state)?->stateName() ?? $state))
                    ->sortable(),

                TextColumn::make('gstin')
                    ->label('GSTIN')
                    ->searchable()
                    ->placeholder('Unregistered'),

                TextColumn::make('assignedUser.name')
                    ->label('Owner')
                    ->sortable()
                    ->visible(fn () => Auth::user()?->isAdmin() ?? false),

                TextColumn::make('lead.name')
                    ->label('Source Lead')
                    ->placeholder('Direct Client')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(collect(IndianState::cases())->mapWithKeys(fn ($case) => [$case->value => $case->stateName()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
