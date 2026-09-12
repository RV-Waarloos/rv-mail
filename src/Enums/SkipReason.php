<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

/**
 * Waarom een kandidaat niet gemaild werd.
 *
 * Overgeslagen bestemmelingen blijven als rij bestaan: wie níét bereikt werd
 * is achteraf meestal precies de informatie die men zoekt.
 */
enum SkipReason: string
{
    case NoEmailAddress = 'no_email_address';
    case Anonymized = 'anonymized';
    case Suppressed = 'suppressed';
    case Unsubscribed = 'unsubscribed';
    case MergedIntoHousehold = 'merged_into_household';

    public function label(): string
    {
        return match ($this) {
            self::NoEmailAddress => 'Geen e-mailadres bekend',
            self::Anonymized => 'Lid is geanonimiseerd',
            self::Suppressed => 'Adres staat op de suppressielijst',
            self::Unsubscribed => 'Uitgeschreven voor deze categorie',
            self::MergedIntoHousehold => 'Samengevoegd met gezinsgenoot',
        };
    }
}
