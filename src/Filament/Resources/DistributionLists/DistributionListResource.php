<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\DistributionLists;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RvWaarloos\RvMail\Filament\Resources\DistributionLists\Pages\EditDistributionList;
use RvWaarloos\RvMail\Filament\Resources\DistributionLists\Pages\ListDistributionLists;
use RvWaarloos\RvMail\Filament\Resources\DistributionLists\RelationManagers\MembersRelationManager;
use RvWaarloos\RvMail\Filament\Resources\DistributionLists\Schemas\DistributionListForm;
use RvWaarloos\RvMail\Filament\Resources\DistributionLists\Tables\DistributionListsTable;
use RvWaarloos\RvMail\Models\DistributionList;
use UnitEnum;

/**
 * Distributielijsten zijn voor groepen die het systeem niet kan afleiden: de
 * ploeg die een evenement organiseert, externe contacten.
 *
 * Alles wat wél afleidbaar is uit de ledenadministratie hoort een doelgroep te
 * zijn. Die blijft vanzelf actueel; een lijst moet iemand bijwerken.
 */
final class DistributionListResource extends Resource
{
    protected static ?string $model = DistributionList::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Communicatie';

    protected static ?string $modelLabel = 'distributielijst';

    protected static ?string $pluralModelLabel = 'distributielijsten';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return DistributionListForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DistributionListsTable::configure($table);
    }

    /** @return list<class-string> */
    public static function getRelations(): array
    {
        return [MembersRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListDistributionLists::route('/'),
            'edit' => EditDistributionList::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('rv-mail.list.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('rv-mail.list.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('rv-mail.list.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('rv-mail.list.manage') ?? false;
    }
}
