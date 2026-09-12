<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Console;

use Illuminate\Console\Command;
use RvWaarloos\RvMail\Support\QuotaGuard;

/**
 * MailerSend biedt geen API om het verbruik op te vragen, dus dit toont de
 * eigen telling. Maandelijks even aftoetsen tegen het dashboard is verstandig.
 */
final class QuotaCommand extends Command
{
    protected $signature = 'rv-mail:quota';

    protected $description = 'Toont het mailverbruik in de lopende factuurperiode';

    public function handle(QuotaGuard $quota): int
    {
        $period = $quota->period();

        $this->components->twoColumnDetail('Periode', $period->label());
        $this->components->twoColumnDetail('Dagen resterend', (string) $period->daysRemaining());
        $this->newLine();

        $this->components->twoColumnDetail('Verbruikt', $quota->usedInPeriod().' / '.$quota->limit());
        $this->components->twoColumnDetail('Percentage', $quota->usagePercentage().'%');
        $this->components->twoColumnDetail('Transactionele reserve', (string) $quota->transactionalReserve());
        $this->components->twoColumnDetail('Beschikbaar voor mailings', (string) $quota->availableForBulk());
        $this->newLine();

        $this->components->twoColumnDetail('API-requests vandaag', (string) $quota->apiRequestsToday());
        $this->components->twoColumnDetail('Requests resterend vandaag', (string) $quota->dailyRequestsRemaining());

        if ($quota->shouldWarn()) {
            $this->newLine();
            $this->components->warn(
                'Meer dan '.config('rv-mail.quota.warn_at_percentage').'% van het maandquotum is verbruikt.'
            );
        }

        return self::SUCCESS;
    }
}
