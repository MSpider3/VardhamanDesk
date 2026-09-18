<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class OverdueFollowUpsWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '⚠️ Overdue Follow-Ups';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Lead::query()
                    ->whereNotNull('next_follow_up_date')
                    ->whereDate('next_follow_up_date', '<', Carbon::today())
                    ->where('status', '!=', LeadStatus::CONVERTED->value)
                    ->with('assignedTo')
                    ->orderBy('next_follow_up_date', 'asc')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Contact Person')
                    ->searchable(),
                TextColumn::make('company')
                    ->label('Company')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Phone'),
                TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->visible(fn () => Auth::user()?->isAdmin() ?? false),
                TextColumn::make('status')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn ($state) => $state instanceof LeadStatus ? $state->label() : (LeadStatus::tryFrom($state)?->label() ?? $state)),
                TextColumn::make('next_follow_up_date')
                    ->label('Follow-Up Date')
                    ->date()
                    ->color('danger')
                    ->description(fn (Lead $record) => Carbon::parse($record->next_follow_up_date)->diffForHumans()),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('View Lead')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Lead $record): string => LeadResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('No overdue follow-ups')
            ->emptyStateDescription('All leads are up to date!');
    }
}
