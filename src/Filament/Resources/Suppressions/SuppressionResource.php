<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Suppressions;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use RvWaarloos\RvMail\Filament\Resources\Suppressions\Pages\ListSuppressions;
use RvWaarloos\RvMail\Filament\Resources\Suppressions\Tables\SuppressionsTable;
use RvWaarloos\RvMail\Models\Suppression;
use UnitEnum;

final class SuppressionResource extends Resource
{
    protected static ?string $model = Suppression::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'Communicatie';

    protected static ?string $modelLabel = 'geblokkeerd adres';

    protected static ?string $pluralModelLabel = 'geblokkeerde adressen';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return SuppressionsTable::configure($table);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListSuppressions::route('/')];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('rv-mail.suppression.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
