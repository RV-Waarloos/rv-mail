<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

/**
 * De soort mail, en daarmee de rechtsgrond en het uitschrijfgedrag.
 *
 * Dit is geen label maar een echte beperking: operationele mail mag geen
 * sluipweg worden voor nieuws dat iemand net heeft afgezet.
 */
enum MailCategory: string
{
    case Transactioneel = 'transactioneel';
    case Operationeel = 'operationeel';
    case Permanentie = 'permanentie';
    case Nieuws = 'nieuws';

    public function label(): string
    {
        return match ($this) {
            self::Transactioneel => 'Transactioneel',
            self::Operationeel => 'Operationeel',
            self::Permanentie => 'Permanentie',
            self::Nieuws => 'Clubnieuws',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Transactioneel => 'Paswoordherstel, accountbevestiging',
            self::Operationeel => 'Wedstrijd verplaatst, lidgeld, uitrusting',
            self::Permanentie => 'Beurtrol kantine',
            self::Nieuws => 'Nieuwsbrief, verslag, eetfestijn',
        };
    }

    public function legalBasis(): LegalBasis
    {
        return match ($this) {
            self::Transactioneel,
            self::Operationeel,
            self::Permanentie => LegalBasis::Contract,
            self::Nieuws => LegalBasis::LegitimateInterest,
        };
    }

    /**
     * Enkel wat op gerechtvaardigd belang steunt is uitschrijfbaar. Mail die de
     * uitvoering van het lidmaatschap dient, kan een lid niet afzetten zonder
     * onbereikbaar te worden voor praktische clubzaken.
     */
    public function isOptOutable(): bool
    {
        return $this->legalBasis() === LegalBasis::LegitimateInterest;
    }

    /** Elke mail krijgt een voetnoot; enkel uitschrijfbare mail krijgt ook een link. */
    public function requiresUnsubscribeLink(): bool
    {
        return $this->isOptOutable();
    }

    public function isTransactional(): bool
    {
        return $this === self::Transactioneel;
    }
}
