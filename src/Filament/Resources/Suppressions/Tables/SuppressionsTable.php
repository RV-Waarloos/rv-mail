<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Suppressions\Tables;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RvWaarloos\RvMail\Contracts\SuppressionStore;
use RvWaarloos\RvMail\Enums\SuppressionReason;
use RvWaarloos\RvMail\Models\Suppression;

final class SuppressionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->label('Adres')->searchable()->copyable(),

                TextColumn::make('reason')
                    ->label('Reden')
                    ->badge()
                    ->formatStateUsing(static fn (SuppressionReason $state): string => $state->label())
                    ->color(static fn (SuppressionReason $state): string => match ($state) {
                        SuppressionReason::SpamComplaint => 'danger',
                        SuppressionReason::HardBounce => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('source')->label('Bron')->placeholder('—')->toggleable(),

                TextColumn::make('suppressed_at')
                    ->label('Sinds')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('suppressed_at', 'desc')
            ->filters([
                SelectFilter::make('reason')
                    ->label('Reden')
                    ->options(array_reduce(
                        SuppressionReason::cases(),
                        static function (array $carry, SuppressionReason $case): array {
                            $carry[$case->value] = $case->label();

                            return $carry;
                        },
                        [],
                    )),
            ])
            ->recordActions([
                Action::make('release')
                    ->label('Deblokkeren')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalDescription('Doe dit alleen als het adres aantoonbaar hersteld is. Bij twijfel: laten staan.')
                    // Een spamklacht heffen we nooit op. Een hard bounce kan
                    // wel hersteld zijn omdat de mailbox weer actief is.
                    ->visible(static fn (Suppression $record): bool => $record->reason->isReversible())
                    ->action(static function (Suppression $record): void {
                        $released = app(SuppressionStore::class)->release($record->email);

                        $released
                            ? Notification::make()->success()->title('Gedeblokkeerd')->send()
                            : Notification::make()->warning()->title('Dit adres kan niet gedeblokkeerd worden')->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
