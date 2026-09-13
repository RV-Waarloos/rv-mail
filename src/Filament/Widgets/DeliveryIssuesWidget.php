<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Enums\SuppressionReason;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\Suppression;

/**
 * Wat er misgaat, op één plek.
 *
 * De harde bounces van de laatste 30 dagen zijn het signaal dat de
 * ledenadministratie bijgewerkt moet worden; spamklachten zijn het signaal dat
 * je iets anders fout doet.
 */
final class DeliveryIssuesWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $since = Carbon::now()->subDays(30);

        $bounces = Suppression::query()
            ->where('reason', SuppressionReason::HardBounce)
            ->where('suppressed_at', '>=', $since)
            ->count();

        $complaints = Suppression::query()
            ->where('reason', SuppressionReason::SpamComplaint)
            ->where('suppressed_at', '>=', $since)
            ->count();

        $failedCampaigns = Campaign::query()
            ->where('count_failed', '>', 0)
            ->count();

        return [
            Stat::make('Harde bounces (30 dagen)', (string) $bounces)
                ->description($bounces > 0 ? 'Adressen om na te kijken in de ledenadministratie' : 'Geen')
                ->color($bounces > 0 ? 'warning' : 'success'),

            Stat::make('Spamklachten (30 dagen)', (string) $complaints)
                ->description($complaints > 0 ? 'Kijk na of de inhoud past bij de categorie' : 'Geen')
                ->color($complaints > 0 ? 'danger' : 'success'),

            Stat::make('Mailings met fouten', (string) $failedCampaigns)
                ->description($failedCampaigns > 0 ? 'Vragen handmatige opvolging' : 'Alles afgerond')
                ->color($failedCampaigns > 0 ? 'warning' : 'success'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('rv-mail.campaign.view') ?? false;
    }
}
