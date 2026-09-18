<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Activity Notes & Follow-ups';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('note')
                    ->label('Note Content')
                    ->required()
                    ->rows(3),
                DatePicker::make('follow_up_date')
                    ->label('Set Next Follow-Up Date (optional)'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('note')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date & Time')
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
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = Auth::id();

                        return $data;
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
