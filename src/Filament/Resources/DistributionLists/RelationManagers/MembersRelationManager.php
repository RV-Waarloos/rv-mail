<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\DistributionLists\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RvWaarloos\RvMail\Contracts\MemberDirectory;
use RvWaarloos\RvMail\Models\DistributionListMember;

/**
 * De leden van een lijst: clubleden via de ledenkiezer, externen via een vrij
 * adresveld.
 *
 * Dat vrije veld zit achter een aparte permissie. Externen mailen is de
 * uitzondering op het uitgangspunt dat de club alleen haar eigen leden
 * aanschrijft, en het is tegelijk de achterdeur waarlangs het systeem alsnog
 * een algemene mailclient kan worden.
 */
final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Leden van deze lijst';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('member_id')
                        ->label('Clublid')
                        ->searchable()
                        ->getSearchResultsUsing(static fn (string $search): array => app(MemberDirectory::class)->search($search))
                        ->getOptionLabelUsing(static fn (mixed $value): ?string => is_numeric($value)
                            ? app(MemberDirectory::class)->labelFor((int) $value)
                            : null)
                        ->helperText('Het adres wordt bij elke mailing opnieuw opgehaald, dus een adreswijziging volgt vanzelf.')
                        ->live(),

                    TextInput::make('email')
                        ->label('Extern e-mailadres')
                        ->email()
                        ->helperText('Alleen voor wie geen clublid is: scheidsrechters, bezoekende clubs.')
                        ->visible(static fn (): bool => auth()->user()?->can('rv-mail.list.manage-external') ?? false)
                        ->requiredWithout('member_id'),

                    TextInput::make('name')
                        ->label('Naam')
                        ->visible(static fn (): bool => auth()->user()?->can('rv-mail.list.manage-external') ?? false),
                ])
                ->columns(1),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('Naam')
                    ->state(static fn (DistributionListMember $record): string => $record->member_id !== null
                        ? (app(MemberDirectory::class)->labelFor($record->member_id) ?? 'Lid #'.$record->member_id)
                        : ($record->name ?? '—')),

                TextColumn::make('email')
                    ->label('Adres')
                    ->placeholder('via ledenadministratie')
                    ->copyable(),

                TextColumn::make('soort')
                    ->label('Soort')
                    ->badge()
                    ->state(static fn (DistributionListMember $record): string => $record->member_id !== null ? 'Clublid' : 'Extern')
                    ->color(static fn (DistributionListMember $record): string => $record->member_id !== null ? 'gray' : 'warning'),
            ])
            ->headerActions([
                CreateAction::make()->label('Toevoegen'),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([])
            ->paginated([25, 50]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('rv-mail.list.manage') ?? false;
    }
}
