<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Console;

use Illuminate\Console\Command;
use RvWaarloos\RvMail\Jobs\ReconcileCampaign;

final class ReconcileCommand extends Command
{
    protected $signature = 'rv-mail:reconcile {--campaign= : Beperk tot één campagne-id}';

    protected $description = 'Zoekt batches die onderweg bleven hangen en beslist of opnieuw verzenden veilig is';

    public function handle(): int
    {
        $campaignId = $this->option('campaign');

        ReconcileCampaign::dispatchSync(
            is_numeric($campaignId) ? (int) $campaignId : null
        );

        $this->components->info('Reconciliatie uitgevoerd.');

        return self::SUCCESS;
    }
}
