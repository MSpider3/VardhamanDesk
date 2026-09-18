<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Contact Person')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof LeadSource ? $state->label() : (LeadSource::tryFrom($state)?->label() ?? $state)),
                TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->sortable()
                    ->visible(fn () => Auth::user()?->isAdmin() ?? false),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (LeadStatus|string $state): string => match ($state instanceof LeadStatus ? $state : LeadStatus::tryFrom($state)) {
                        LeadStatus::NEW => 'info',
                        LeadStatus::CONTACTED => 'warning',
                        LeadStatus::QUALIFIED => 'primary',
                        LeadStatus::CONVERTED => 'success',
                        LeadStatus::LOST => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof LeadStatus ? $state->label() : (LeadStatus::tryFrom($state)?->label() ?? $state)),
                TextColumn::make('next_follow_up_date')
                    ->label('Next Follow-Up')
                    ->date()
                    ->sortable()
                    ->color(function (Lead $record): ?string {
                        if (! $record->next_follow_up_date || $record->status === LeadStatus::CONVERTED) {
                            return null;
                        }
                        $date = Carbon::parse($record->next_follow_up_date);
                        if ($date->isBefore(Carbon::today())) {
                            return 'danger';
                        }
                        if ($date->isSameDay(Carbon::today())) {
                            return 'warning';
                        }

                        return null;
                    })
                    ->description(function (Lead $record): ?string {
                        if (! $record->next_follow_up_date || $record->status === LeadStatus::CONVERTED) {
                            return null;
                        }
                        $date = Carbon::parse($record->next_follow_up_date);
                        if ($date->isBefore(Carbon::today())) {
                            return 'Overdue';
                        }
                        if ($date->isSameDay(Carbon::today())) {
                            return 'Due Today';
                        }

                        return null;
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(LeadStatus::class),
                SelectFilter::make('source')
                    ->options(LeadSource::class),
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
