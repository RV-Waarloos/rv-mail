<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Filament\Actions\CampaignActions;
use RvWaarloos\RvMail\Models\Campaign;

final class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('category')
                    ->label('Soort')
                    ->badge()
                    ->formatStateUsing(static fn (MailCategory $state): string => $state->label())
                    ->color(static fn (MailCategory $state): string => $state->isOptOutable() ? 'info' : 'gray'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (CampaignStatus $state): string => $state->label())
                    ->color(static fn (CampaignStatus $state): string => match ($state) {
                        CampaignStatus::Sent => 'success',
                        CampaignStatus::Failed => 'danger',
                        CampaignStatus::Dispatching => 'warning',
                        CampaignStatus::PendingApproval => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('recipients_sendable')
                    ->label('Aantal')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('count_delivered')
                    ->label('Afgeleverd')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                // Kleurcode op het bounce-percentage: een verouderde
                // adressenlijst zie je zo meteen, zonder te rekenen.
                TextColumn::make('bounce_rate')
                    ->label('Bounces')
                    ->state(static fn (Campaign $record): string => self::bounceLabel($record))
                    ->badge()
                    ->color(static fn (Campaign $record): string => match (true) {
                        self::bounceRate($record) >= 5.0 => 'danger',
                        self::bounceRate($record) >= 2.0 => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('dispatched_at')
                    ->label('Verzonden')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(array_reduce(
                        CampaignStatus::cases(),
                        static function (array $carry, CampaignStatus $case): array {
                            $carry[$case->value] = $case->label();

                            return $carry;
                        },
                        [],
                    )),

                SelectFilter::make('category')
                    ->label('Soort')
                    ->options(array_reduce(
                        MailCategory::cases(),
                        static function (array $carry, MailCategory $case): array {
                            $carry[$case->value] = $case->label();

                            return $carry;
                        },
                        [],
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(static fn (Campaign $record): bool => $record->status->canCompose()),
                CampaignActions::compose(),
                CampaignActions::dispatch(),
            ])
            // Bewust geen bulk verwijderen: een campagne weggooien wist ook het
            // snapshot, en dat is precies wat je achteraf nodig hebt.
            ->toolbarActions([]);
    }

    private static function bounceRate(Campaign $record): float
    {
        $sent = $record->count_sent;

        if ($sent === 0) {
            return 0.0;
        }

        return round(($record->count_hard_bounced + $record->count_soft_bounced) / $sent * 100, 1);
    }

    private static function bounceLabel(Campaign $record): string
    {
        return $record->count_sent === 0 ? '—' : self::bounceRate($record).'%';
    }
}
