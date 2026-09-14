<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\Pages;

use Filament\Resources\Pages\CreateRecord;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\CampaignResource;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\Schemas\CampaignForm;

final class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['from_email'] = config('rv-mail.from.address');
        $data['from_name'] = config('rv-mail.from.name');

        return CampaignForm::collectAudienceParams($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
