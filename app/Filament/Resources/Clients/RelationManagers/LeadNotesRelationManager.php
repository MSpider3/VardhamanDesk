<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LeadNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'leadNotes';

    protected static ?string $title = 'Originating Lead Activity Notes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return filled($ownerRecord->lead_id);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('note')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Note Date')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('createdBy.name')
                    ->label('Author')
                    ->sortable(),

                TextColumn::make('note')
                    ->label('Note')
                    ->wrap(),

                TextColumn::make('follow_up_date')
                    ->label('Follow-Up Date')
                    ->date()
                    ->placeholder('None scheduled'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
