<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\Pages\CreateCampaign;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\Pages\EditCampaign;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\Pages\ListCampaigns;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\Pages\ViewCampaign;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\RelationManagers\RecipientsRelationManager;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\Schemas\CampaignForm;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\Schemas\CampaignInfolist;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\Tables\CampaignsTable;
use RvWaarloos\RvMail\Models\Campaign;
use UnitEnum;

final class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|UnitEnum|null $navigationGroup = 'Communicatie';

    protected static ?string $modelLabel = 'mailing';

    protected static ?string $pluralModelLabel = 'mailings';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return CampaignForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CampaignInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CampaignsTable::configure($table);
    }

    /** @return list<class-string> */
    public static function getRelations(): array
    {
        return [RecipientsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'view' => ViewCampaign::route('/{record}'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }

    // Het package declareert permissienamen en toetst via de Gate. Welke rollen
    // die permissies krijgen, hoort in het platformbrede rollenontwerp.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('rv-mail.campaign.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('rv-mail.campaign.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->can('rv-mail.campaign.create') ?? false)
            && $record instanceof Campaign
            && $record->status->canCompose();
    }

    public static function canDelete(Model $record): bool
    {
        // Nooit. Een campagne weggooien wist het snapshot, en dat is precies
        // wat je nodig hebt als er ooit een vraag over komt.
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Campaign::query()
            ->whereIn('status', ['dispatching', 'pending_approval'])
            ->count();

        return $count > 0 ? (string) $count : null;
    }
}
