<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\DistributionLists\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use RvWaarloos\RvMail\Filament\Resources\DistributionLists\DistributionListResource;

final class EditDistributionList extends EditRecord
{
    protected static string $resource = DistributionListResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
