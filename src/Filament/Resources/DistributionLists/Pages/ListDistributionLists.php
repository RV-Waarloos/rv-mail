<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\DistributionLists\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use RvWaarloos\RvMail\Filament\Resources\DistributionLists\DistributionListResource;

final class ListDistributionLists extends ListRecords
{
    protected static string $resource = DistributionListResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nieuwe lijst')];
    }

    public function getSubheading(): string
    {
        return 'Voor groepen die niet uit de ledenadministratie af te leiden zijn. '
            .'Alle actieve leden of een volledige afdeling kies je bij het opstellen van de mailing.';
    }
}
