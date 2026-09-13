<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\CampaignResource;
use RvWaarloos\RvMail\Filament\Widgets\QuotaWidget;

final class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nieuwe mailing')];
    }

    /** @return list<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [QuotaWidget::class];
    }
}
