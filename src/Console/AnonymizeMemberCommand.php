<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Console;

use Illuminate\Console\Command;
use RvWaarloos\RvMail\Support\RecipientAnonymizer;

final class AnonymizeMemberCommand extends Command
{
    protected $signature = 'rv-mail:anonymize-member {member : Het lid-id}';

    protected $description = 'Koppelt de mailgeschiedenis van een lid los bij anonimisering';

    public function handle(RecipientAnonymizer $anonymizer): int
    {
        $memberId = $this->argument('member');

        if (! is_numeric($memberId)) {
            $this->components->error('Geef een geldig lid-id op.');

            return self::FAILURE;
        }

        $count = $anonymizer->forMember((int) $memberId);

        $this->components->info("{$count} bestemmelingrij(en) geanonimiseerd.");

        return self::SUCCESS;
    }
}
