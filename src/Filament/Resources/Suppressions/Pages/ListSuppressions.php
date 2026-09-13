<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Suppressions\Pages;

use Filament\Resources\Pages\ListRecords;
use RvWaarloos\RvMail\Filament\Resources\Suppressions\SuppressionResource;
use RvWaarloos\RvMail\Filament\Widgets\DeliveryIssuesWidget;

final class ListSuppressions extends ListRecords
{
    protected static string $resource = SuppressionResource::class;

    /** @return list<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [DeliveryIssuesWidget::class];
    }
}
