<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\DistributionLists\Tables;

use Carbon\CarbonInterface;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Models\DistributionList;

final class DistributionListsTable
{
    /** Vanaf wanneer een lijst als vergeten geldt. */
    private const int VERGETEN_MAANDEN = 6;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable()
                    ->weight('medium')
                    ->description(static fn (DistributionList $record): ?string => $record->description),

                TextColumn::make('members_count')
                    ->label('Leden')
                    ->counts('members')
                    ->alignEnd(),

                IconColumn::make('is_active')
                    ->label('Actief')
                    ->boolean(),

                // Een lijst die niemand meer gebruikt is een lijst die niemand
                // meer onderhoudt. Zichtbaar maken, niet automatisch opruimen.
                TextColumn::make('last_used_at')
                    ->label('Laatst gebruikt')
                    ->formatStateUsing(static fn (?CarbonInterface $state): string => $state?->diffForHumans() ?? 'nooit')
                    // ->formatStateUsing(static fn (?Carbon $state): string => $state?->diffForHumans() ?? 'nooit')
                    ->badge()
                    ->color(static fn (DistributionList $record): string => self::vergeten($record) ? 'warning' : 'gray')
                    ->tooltip(static fn (DistributionList $record): ?string => self::vergeten($record)
                        ? 'Deze lijst is al lang niet gebruikt. Klopt hij nog?'
                        : null),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label('Actief'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('De lijst en zijn leden verdwijnen. Verzonden mailings blijven bewaard.'),
            ])
            ->toolbarActions([]);
    }

    private static function vergeten(DistributionList $record): bool
    {
        if ($record->last_used_at === null) {
            // Een nieuwe lijst is niet vergeten; die is gewoon nog niet gebruikt.
            return $record->created_at !== null
                && $record->created_at->lt(Carbon::now()->subMonths(self::VERGETEN_MAANDEN));
        }

        return $record->last_used_at->lt(Carbon::now()->subMonths(self::VERGETEN_MAANDEN));
    }
}
