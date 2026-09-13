<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use RvWaarloos\RvMail\Filament\Actions\CampaignActions;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\CampaignResource;
use RvWaarloos\RvMail\Models\Campaign;

final class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            CampaignActions::sendTest(),
            CampaignActions::compose(),
            CampaignActions::approve(),
            CampaignActions::dispatch(),
            CampaignActions::retryFailed(),
            CampaignActions::cancel(),
        ];
    }

    /**
     * De statuspagina moet uit zichzelf bijwerken terwijl een mailing loopt,
     * anders zit de verantwoordelijke te verversen.
     */
    protected function getPollingInterval(): ?string
    {
        $record = $this->getRecord();

        return $record instanceof Campaign && ! $record->status->isTerminal() ? '15s' : null;
    }
}
