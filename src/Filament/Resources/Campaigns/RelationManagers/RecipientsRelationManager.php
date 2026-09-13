<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Enums\SkipReason;

/**
 * De bestemmelingenlijst, inclusief wie is overgeslagen en waarom.
 *
 * Dat laatste is meestal de eigenlijke vraag: "waarom heeft Peter die mail niet
 * gekregen?" Daarom staan overgeslagen rijen hier gewoon tussen en zijn ze niet
 * weggefilterd.
 */
final class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Bestemmelingen';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->label('Adres')->searchable(),
                TextColumn::make('name')->label('Naam')->searchable()->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (RecipientStatus $state): string => $state->label())
                    ->color(static fn (RecipientStatus $state): string => match (true) {
                        $state === RecipientStatus::Delivered => 'success',
                        $state->isFailure() => 'danger',
                        $state === RecipientStatus::Suppressed => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('skip_reason')
                    ->label('Reden')
                    ->formatStateUsing(static fn (?SkipReason $state): string => $state?->label() ?? '—')
                    ->wrap(),

                TextColumn::make('failure_reason')
                    ->label('Foutmelding')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('delivered_at')
                    ->label('Afgeleverd')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(array_reduce(
                        RecipientStatus::cases(),
                        static function (array $carry, RecipientStatus $case): array {
                            $carry[$case->value] = $case->label();

                            return $carry;
                        },
                        [],
                    )),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([25, 50, 100]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('rv-mail.campaign.view') ?? false;
    }
}
