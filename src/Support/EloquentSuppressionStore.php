<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Support;

use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Contracts\SuppressionStore;
use RvWaarloos\RvMail\Enums\SuppressionReason;
use RvWaarloos\RvMail\Models\Suppression;

final class EloquentSuppressionStore implements SuppressionStore
{
    private const int CHUNK_SIZE = 500;

    public function isSuppressed(string $email): bool
    {
        return Suppression::query()
            ->where('email', $this->normalize($email))
            ->exists();
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, SuppressionReason>
     */
    public function reasonsFor(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $normalized = array_values(array_unique(array_map($this->normalize(...), $emails)));
        $found = [];

        foreach (array_chunk($normalized, self::CHUNK_SIZE) as $chunk) {
            $rows = Suppression::query()
                ->whereIn('email', $chunk)
                ->get(['email', 'reason']);

            foreach ($rows as $row) {
                $found[$row->email] = $row->reason;
            }
        }

        return $found;
    }

    public function suppress(
        string $email,
        SuppressionReason $reason,
        ?int $memberId = null,
        ?string $source = null,
    ): void {
        Suppression::query()->updateOrCreate(
            ['email' => $this->normalize($email)],
            [
                'reason' => $reason,
                'member_id' => $memberId,
                'source' => $source,
                'suppressed_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Een spamklacht heffen we nooit automatisch op; een hard bounce kan wel
     * hersteld zijn omdat de mailbox weer actief is.
     */
    public function release(string $email): bool
    {
        $record = Suppression::query()
            ->where('email', $this->normalize($email))
            ->first();

        if (! $record instanceof Suppression || ! $record->reason->isReversible()) {
            return false;
        }

        return (bool) $record->delete();
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
