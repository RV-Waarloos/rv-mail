<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use RvWaarloos\RvMail\Filament\Resources\Campaigns\CampaignResource;
use RvWaarloos\RvMail\Filament\Resources\DistributionLists\DistributionListResource;
use RvWaarloos\RvMail\Filament\Resources\Suppressions\SuppressionResource;
use RvWaarloos\RvMail\Filament\Widgets\DeliveryIssuesWidget;
use RvWaarloos\RvMail\Filament\Widgets\QuotaWidget;

/**
 * Registreert de mailschermen in een panel.
 *
 * Als plugin en niet automatisch vanuit de service provider: de club-app
 * bepaalt zelf in welk panel de schermen horen, en of de widgets op het
 * dashboard komen.
 *
 *   ->plugin(RvMailPlugin::make())
 */
final class RvMailPlugin implements Plugin
{
    private bool $dashboardWidgets = true;

    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'rv-mail';
    }

    /** Zet de widgets uit wanneer het dashboard al vol staat. */
    public function withoutDashboardWidgets(): self
    {
        $this->dashboardWidgets = false;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            CampaignResource::class,
            DistributionListResource::class,
            SuppressionResource::class,
        ]);

        if ($this->dashboardWidgets) {
            $panel->widgets([
                QuotaWidget::class,
                DeliveryIssuesWidget::class,
            ]);
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
