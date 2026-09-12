<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Transport;

/**
 * Verdeelt berichten over batches.
 *
 * Twee grenzen tegelijk, want de naieve chunk van 500 is riskant: 500 berichten
 * van 80 KB zit rond de 40 MB, tegen een plafond van 50 MB bij MailerSend.
 *
 * Daarnaast worden gedeelde gezinsadressen gespreid. Bij de standaardstrategie
 * per_member krijgen drie kinderen op hetzelfde adres elk hun eigen mail; die
 * binnen een halve seconde naast elkaar afleveren wordt door sommige providers
 * als bulk-gedrag gelezen.
 */
final class BatchChunker
{
    public function __construct(
        private readonly int $maxMessages,
        private readonly int $maxPayloadBytes,
        private readonly bool $spreadSharedAddresses = true,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxMessages: (int) config('rv-mail.batch.max_messages', 100),
            maxPayloadBytes: (int) config('rv-mail.batch.max_payload_bytes', 20 * 1024 * 1024),
            spreadSharedAddresses: (bool) config('rv-mail.deduplication.spread_shared_addresses', true),
        );
    }

    /**
     * @param  list<BulkMessage>  $messages
     * @param  array{address: string, name: string}  $from
     * @return list<array{messages: list<BulkMessage>, bytes: int}>
     */
    public function chunk(array $messages, array $from, ?string $replyTo, bool $trackClicks): array
    {
        if ($messages === []) {
            return [];
        }

        if ($this->spreadSharedAddresses) {
            $messages = $this->spread($messages);
        }

        /** @var list<array{messages: list<BulkMessage>, bytes: int}> $batches */
        $batches = [];

        /** @var list<BulkMessage> $current */
        $current = [];
        $currentBytes = 0;

        foreach ($messages as $message) {
            $bytes = $this->measure($message, $from, $replyTo, $trackClicks);

            $wouldExceedCount = count($current) >= $this->maxMessages;
            $wouldExceedBytes = $current !== [] && ($currentBytes + $bytes) > $this->maxPayloadBytes;

            if ($wouldExceedCount || $wouldExceedBytes) {
                $batches[] = ['messages' => $current, 'bytes' => $currentBytes];
                $current = [];
                $currentBytes = 0;
            }

            $current[] = $message;
            $currentBytes += $bytes;
        }

        if ($current !== []) {
            $batches[] = ['messages' => $current, 'bytes' => $currentBytes];
        }

        return $batches;
    }

    /**
     * Herschikt zo dat berichten naar hetzelfde adres zo ver mogelijk uit
     * elkaar komen te liggen: eerst groeperen per adres, dan om beurten één uit
     * elke groep nemen.
     *
     * @param  list<BulkMessage>  $messages
     * @return list<BulkMessage>
     */
    private function spread(array $messages): array
    {
        /** @var array<string, list<BulkMessage>> $groups */
        $groups = [];

        foreach ($messages as $message) {
            $groups[mb_strtolower($message->email)][] = $message;
        }

        // Niets gedeeld: geen reden om de volgorde te wijzigen.
        $hasShared = false;

        foreach ($groups as $group) {
            if (count($group) > 1) {
                $hasShared = true;
                break;
            }
        }

        if (! $hasShared) {
            return $messages;
        }

        /** @var list<BulkMessage> $spread */
        $spread = [];
        $previous = null;
        $total = count($messages);

        while (count($spread) < $total) {
            $pick = null;
            $pickSize = 0;

            // Grootste groep die niet de vorige is: zo raakt de grootste groep
            // gelijkmatig verdeeld in plaats van achteraan op te stapelen.
            foreach ($groups as $key => $group) {
                if ($group === [] || $key === $previous) {
                    continue;
                }

                if (count($group) > $pickSize) {
                    $pick = $key;
                    $pickSize = count($group);
                }
            }

            // Alleen de vorige groep heeft nog berichten: dan kan het niet
            // anders. Gebeurt wanneer één adres meer dan de helft uitmaakt.
            if ($pick === null) {
                foreach ($groups as $key => $group) {
                    if ($group !== []) {
                        $pick = $key;
                        break;
                    }
                }
            }

            if ($pick === null) {
                break;
            }

            $spread[] = array_shift($groups[$pick]);
            $previous = $pick;
        }

        return $spread;
    }

    /**
     * @param  array{address: string, name: string}  $from
     */
    private function measure(BulkMessage $message, array $from, ?string $replyTo, bool $trackClicks): int
    {
        $encoded = json_encode(
            $message->toPayload($from, $replyTo, $trackClicks),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        // Kan alleen falen op ongeldige UTF-8; dan is een ruime schatting
        // veiliger dan doen alsof het bericht niets weegt.
        return $encoded === false ? $this->maxPayloadBytes : strlen($encoded);
    }
}
