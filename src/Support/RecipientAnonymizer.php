<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Support;

use Illuminate\Support\Facades\DB;
use RvWaarloos\RvMail\Models\CampaignRecipient;

/**
 * Koppelt de mailgeschiedenis van een lid los bij anonimisering.
 *
 * De rijen blijven bestaan, want de statistiek van een campagne moet kloppen:
 * wie ooit 340 mails verstuurde, moet dat aantal over vijf jaar nog kunnen
 * verantwoorden. Wat verdwijnt is de herleidbaarheid naar de persoon.
 *
 * Aanroepen vanuit de anonymize()-methode van Member in rv-core.
 */
final class RecipientAnonymizer
{
    public function forMember(int $memberId): int
    {
        $rows = CampaignRecipient::query()
            ->where('member_id', $memberId)
            ->get(['id', 'email']);

        foreach ($rows as $row) {
            $row->forceFill([
                'member_id' => null,
                // Een hash en geen lege string: zo blijven twee rijen van
                // hetzelfde oude adres nog steeds als hetzelfde herkenbaar,
                // zonder dat het adres zelf te achterhalen is.
                'email' => 'geanonimiseerd+'.substr(hash('sha256', $row->email), 0, 16).'@invalid',
                'name' => null,
                'personalization' => null,
            ])->save();
        }

        // Suppressies en uitschrijvingen van dit lid verdwijnen: zonder adres
        // hebben ze geen functie meer, en ze bewaren zou het doel voorbijschieten.
        DB::connection('central')->table('mail_suppressions')->where('member_id', $memberId)->delete();
        DB::connection('central')->table('mail_unsubscribes')->where('member_id', $memberId)->delete();

        return $rows->count();
    }
}
