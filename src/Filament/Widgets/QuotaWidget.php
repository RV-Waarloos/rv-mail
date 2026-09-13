<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use RvWaarloos\RvMail\Support\QuotaGuard;

/**
 * Het maandbudget in beeld.
 *
 * MailerSend biedt geen API om het verbruik op te vragen, dus dit is de eigen
 * telling. Maandelijks aftoetsen tegen het dashboard blijft verstandig.
 */
final class QuotaWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $quota = QuotaGuard::forCurrentPeriod();
        $used = $quota->usedInPeriod();
        $limit = $quota->limit();
        $percentage = $quota->usagePercentage();

        return [
            Stat::make('Verbruikt deze periode', "{$used} / {$limit}")
                ->description($percentage.'% van het maandplafond')
                ->descriptionIcon($quota->shouldWarn() ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-chart-bar')
                ->color(match (true) {
                    $percentage >= 95 => 'danger',
                    $quota->shouldWarn() => 'warning',
                    default => 'success',
                }),

            Stat::make('Beschikbaar voor mailings', (string) $quota->availableForBulk())
                ->description('Na aftrek van '.$quota->transactionalReserve().' voor transactionele mail')
                ->color($quota->availableForBulk() < 500 ? 'warning' : 'gray'),

            Stat::make('Periode', $quota->period()->label())
                ->description($quota->period()->daysRemaining().' dagen resterend')
                ->color('gray'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('rv-mail.campaign.view') ?? false;
    }
}
