<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use RvWaarloos\RvMail\Filament\Actions\CampaignActions;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\CampaignResource;

final class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            CampaignActions::sendTest(),
            CampaignActions::compose(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
