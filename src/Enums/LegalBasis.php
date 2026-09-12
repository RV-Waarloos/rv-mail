<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

/**
 * De AVG-rechtsgrond waarop een categorie mail steunt.
 *
 * De club stuurt geen commerciële mail en mailt geen niet-leden of oud-leden,
 * waardoor toestemming als rechtsgrond niet voorkomt. Zou dat ooit veranderen,
 * dan hoort daar een nieuwe categorie bij mét opt-in-registratie en bewijslast,
 * en geen uitbreiding van een bestaande.
 */
enum LegalBasis: string
{
    /** Artikel 6.1.b — noodzakelijk voor de uitvoering van het lidmaatschap. */
    case Contract = 'contract';

    /** Artikel 6.1.f — gerechtvaardigd belang, met absoluut bezwaarrecht. */
    case LegitimateInterest = 'legitimate_interest';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Uitvoering van de lidmaatschapsovereenkomst',
            self::LegitimateInterest => 'Gerechtvaardigd belang',
        };
    }

    public function article(): string
    {
        return match ($this) {
            self::Contract => 'art. 6.1.b AVG',
            self::LegitimateInterest => 'art. 6.1.f AVG',
        };
    }
}
