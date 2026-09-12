<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

/**
 * Hoe om te gaan met een gezin dat één e-mailadres deelt.
 *
 * Het datamodel laat toe dat meerdere leden hetzelfde adres gebruiken terwijl
 * elk lid een eigen account heeft.
 */
enum DedupStrategy: string
{
    /**
     * De standaard. Elk lid krijgt zijn eigen gepersonaliseerde bericht. Drie
     * kinderen op hetzelfde adres betekent drie mails en drie credits — bewuste
     * keuze, want bij persoonsgebonden inhoud is samenvoegen ronduit fout.
     */
    case PerMember = 'per_member';

    /**
     * Eén mail per adres, met samengevoegde personalisatie. Zinvol bij zuiver
     * informatieve clubcommunicatie waar de inhoud voor iedereen identiek is.
     */
    case PerEmail = 'per_email';

    public function label(): string
    {
        return match ($this) {
            self::PerMember => 'Eén mail per lid',
            self::PerEmail => 'Eén mail per adres',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PerMember => 'Elk gezinslid krijgt een eigen, persoonlijk bericht.',
            self::PerEmail => 'Gezinsleden op hetzelfde adres krijgen samen één bericht.',
        };
    }
}
