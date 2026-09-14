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
     * Beschrijving van de parameters, waaruit het formulier zijn velden bouwt.
     *
     * Het formulier kent geen enkele doelgroep: een nieuwe audience in de
     * club-app verschijnt vanzelf met de juiste velden zodra ze hier beschreven
     * staan. Levert dit een lege array op, dan heeft de doelgroep geen
     * parameters nodig — zoals "alle actieve leden".
     *
     * @return array<string, array{
     *     type: 'select'|'multiselect'|'text'|'number',
     *     label: string,
     *     required: bool,
     *     options?: array<int|string, string>,
     *     helper?: string,
     *     default?: int|string|null
     * }>
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

    /**
     * Beschrijft de gekozen parameters in mensentaal, voor het overzicht en het
     * preflight-scherm.
     *
     * "Distributielijst" zegt niets; "Distributielijst: Eetfestijn 2026" wel.
     *
     * @param  array<string, mixed>  $params
     */
    public function describe(array $params): string;
}
