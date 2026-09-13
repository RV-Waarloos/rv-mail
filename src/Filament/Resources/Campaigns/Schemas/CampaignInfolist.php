<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use RvWaarloos\RvMail\Models\Campaign;

final class CampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Verloop')
                ->schema([
                    TextEntry::make('recipients_sendable')->label('Bestemmelingen'),
                    TextEntry::make('count_sent')->label('Verzonden'),
                    TextEntry::make('count_delivered')->label('Afgeleverd'),
                    TextEntry::make('count_hard_bounced')
                        ->label('Definitief geweigerd')
                        ->color(static fn (Campaign $record): string => $record->count_hard_bounced > 0 ? 'danger' : 'gray'),
                    TextEntry::make('count_soft_bounced')->label('Tijdelijk geweigerd'),
                    TextEntry::make('count_complained')
                        ->label('Spamklachten')
                        ->color(static fn (Campaign $record): string => $record->count_complained > 0 ? 'danger' : 'gray'),
                ])
                // Geen openingspercentage: dat wordt bewust niet gemeten.
                ->columns(3),

            Section::make('Details')
                ->schema([
                    TextEntry::make('category')
                        ->label('Soort')
                        ->formatStateUsing(static fn (Campaign $record): string => $record->category->label()),
                    TextEntry::make('category_basis')
                        ->label('Rechtsgrond')
                        ->state(static fn (Campaign $record): string => $record->category->legalBasis()->label()),
                    TextEntry::make('dedup_strategy')
                        ->label('Gezinsadressen')
                        ->state(static fn (Campaign $record): string => $record->dedup_strategy->label()),
                    TextEntry::make('composed_at')->label('Samengesteld')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('dispatched_at')->label('Verzonden')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('approved_at')->label('Goedgekeurd')->dateTime('d/m/Y H:i')->placeholder('—'),
                ])
                ->columns(3),
        ]);
    }
}
