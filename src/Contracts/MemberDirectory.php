<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Contracts;

use RvWaarloos\RvMail\Campaigns\RecipientCandidate;

/**
 * Laat het package leden opzoeken zonder het ledenmodel te kennen.
 *
 * Hetzelfde patroon als de audiences: rv-mail kent geen Member, dus de club-app
 * vult dit in.
 */
interface MemberDirectory
{
    /**
     * Zoek leden op naam, adres of lidnummer.
     *
     * @return array<int, string> lid-id => label, bijvoorbeeld "Jan Peeters (0004)"
     */
    public function search(string $term, int $limit = 25): array;

    /** Label voor een reeds geselecteerd lid, voor het tonen van een bestaande keuze. */
    public function labelFor(int $memberId): ?string;

    /**
     * Het e-mailadres van een lid op het moment van opvragen.
     *
     * Wordt niet opgeslagen in een distributielijst: die bewaart het lid-id, en
     * het adres wordt bij elke mailing opnieuw opgehaald. Zo blijft een lijst
     * kloppen wanneer iemand van adres verandert.
     */
    public function emailFor(int $memberId): ?string;

    /**
     * Het lid als volwaardige bestemmeling, met dezelfde personalisatievelden
     * als een ledendoelgroep zou opleveren.
     *
     * Zonder dit kan een mailing naar een distributielijst niet personaliseren,
     * ook niet als die lijst uit clubleden bestaat — en dan spreek je een
     * evenementenploeg aan met "Beste" in plaats van "Beste Sofie". Precies het
     * verschil waarom we geen bcc gebruiken.
     *
     * Geeft null terug wanneer het lid niet bestaat of geen adres heeft.
     */
    public function candidateFor(int $memberId): ?RecipientCandidate;
}
