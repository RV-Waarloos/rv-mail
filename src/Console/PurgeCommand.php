<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ruimt op volgens de bewaartermijnen uit de configuratie.
 *
 * Campagnes zelf blijven onbeperkt bewaard: de inhoud van clubcommunicatie is
 * archiefwaardig. Wat weggaat is de ruwe webhookdata en het gedetailleerde
 * eventlog.
 */
final class PurgeCommand extends Command
{
    protected $signature = 'rv-mail:purge {--dry-run : Toon wat er zou verdwijnen}';

    protected $description = 'Verwijdert verlopen webhookleveringen, events en quotumregels';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $targets = [
            'Webhookleveringen' => ['table' => 'mail_webhook_deliveries', 'column' => 'created_at', 'days' => (int) config('rv-mail.retention.webhook_deliveries', 30)],
            'Events' => ['table' => 'mail_events', 'column' => 'received_at', 'days' => (int) config('rv-mail.retention.events', 396)],
            'Quotumregels' => ['table' => 'mail_quota_ledger', 'column' => 'date', 'days' => (int) config('rv-mail.retention.quota_ledger', 730)],
        ];

        foreach ($targets as $label => $target) {
            $cutoff = Carbon::now()->subDays($target['days']);
            $query = DB::connection('central')->table($target['table'])->where($target['column'], '<', $cutoff);

            if ($dryRun) {
                $this->components->twoColumnDetail(
                    $label.' vóór '.$cutoff->format('d/m/Y'),
                    (string) $query->count().' rijen'
                );

                continue;
            }

            // In stukken verwijderen: een DELETE over dertien maanden events kan
            // een grote tabel lang blokkeren.
            $deleted = 0;

            do {
                $batch = $query->clone()->limit(1_000)->delete();
                $deleted += $batch;
            } while ($batch > 0);

            $this->components->twoColumnDetail($label, $deleted.' verwijderd');
        }

        return self::SUCCESS;
    }
}
