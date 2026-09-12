<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Campaigns;

/**
 * Eén mogelijke bestemmeling zoals een doelgroep hem oplevert.
 *
 * Dit is het volledige contactoppervlak tussen rv-mail en het ledenmodel: het
 * package kent geen Member, alleen dit object. Een doelgroep in de club-app
 * bepaalt zelf hoe ze eraan komt.
 */
final readonly class RecipientCandidate
{
    /**
     * @param  array<string, mixed>  $personalization
     * @param  bool  $anonymized  Geanonimiseerde leden worden nooit gemaild, maar
     *                            blijven wel zichtbaar in het snapshot met een reden.
     */
    public function __construct(
        public string $email,
        public ?string $name = null,
        public ?int $memberId = null,
        public array $personalization = [],
        public bool $anonymized = false,
    ) {}

    /**
     * Adressen worden genormaliseerd voor vergelijking: een gezin dat
     * "Jan.Peeters@telenet.be" en "jan.peeters@telenet.be" door elkaar gebruikt,
     * is één adres.
     */
    public function normalizedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }

    public function hasEmail(): bool
    {
        return trim($this->email) !== '';
    }

    public function firstName(): ?string
    {
        if ($this->name === null || trim($this->name) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($this->name));

        return $parts === false || $parts === [] ? null : $parts[0];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function withPersonalization(array $extra): self
    {
        return new self(
            email: $this->email,
            name: $this->name,
            memberId: $this->memberId,
            personalization: array_merge($this->personalization, $extra),
            anonymized: $this->anonymized,
        );
    }
}
