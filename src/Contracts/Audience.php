<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use RvWaarloos\RvMail\Campaigns\RecipientCandidate;

/**
 * Een doelgroep die aangesproken kan worden.
 *
 * Implementaties leven in de club-app, niet in dit package: rv-mail kent geen
 * Member en werkt uitsluitend met RecipientCandidate. Zo blijft de workbench
 * licht en kan het package getest worden zonder ledenmodel.
 */
interface Audience
{
    /** Stabiele sleutel, gebruikt in configuratie en in het snapshot. */
    public function key(): string;

    public function label(): string;

    /**
     * Beschrijving van de parameters voor de UI.
     *
     * @return array<string, array{type: string, label: string, required: bool}>
     */
    public function parameterSchema(): array;

    /**
     * @param  array<string, mixed>  $params
     * @return iterable<RecipientCandidate>
     */
    public function resolve(array $params): iterable;

    /**
     * Mag deze gebruiker deze doelgroep met déze parameters aanspreken?
     *
     * De permissie zegt wát iemand mag, dit zegt vóór wie. Een
     * afdelingsverantwoordelijke mag verzenden, maar enkel naar de eigen
     * afdeling — dat is een parametrische beperking, geen permissie.
     *
     * @param  array<string, mixed>  $params
     */
    public function authorize(Authorizable $user, array $params): bool;
}
