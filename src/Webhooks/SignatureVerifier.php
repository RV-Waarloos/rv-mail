<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Webhooks;

/**
 * MailerSend ondertekent elke levering met HMAC-SHA256 over de ruwe body,
 * hexadecimaal, in de header `Signature`.
 *
 * Cruciaal: de handtekening geldt over de bytes zoals ze binnenkwamen. Eerst
 * decoderen en daarna opnieuw encoderen levert een andere string op, want
 * sleutelvolgorde en escaping hoeven niet bewaard te blijven.
 */
final readonly class SignatureVerifier
{
    public function __construct(
        private string $secret,
    ) {}

    public function verify(string $rawBody, ?string $signature): bool
    {
        if ($this->secret === '' || $signature === null || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->secret);

        // hash_equals en niet ===, om timing attacks uit te sluiten.
        return hash_equals($expected, $signature);
    }

    /** Gebruikt door het simulatiecommando om een geldige levering na te bootsen. */
    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->secret);
    }
}
