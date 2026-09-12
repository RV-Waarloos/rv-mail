<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use RvWaarloos\RvMail\Audiences\AudienceScopeSet;

/**
 * Levert het bereik van een gebruiker.
 *
 * Dit is het enige stuk autorisatie dat rv-mail niet kan delegeren aan een
 * permissiepackage. `rv-mail.campaign.send` zegt niets over wélke afdeling
 * iemand mag aanspreken, en spatie/laravel-permission modelleert dat ook niet.
 *
 * De host-app vult dit in: uit een verantwoordelijke-relatie in rv-core, uit de
 * teams-functie van een permissiepackage, of uit iets anders. rv-mail heeft er
 * alleen het antwoord van nodig.
 */
interface AudienceScopeResolver
{
    public function scopesFor(Authorizable $user): AudienceScopeSet;
}
