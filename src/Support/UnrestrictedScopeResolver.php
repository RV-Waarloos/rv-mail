<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use RvWaarloos\RvMail\Audiences\AudienceScopeSet;
use RvWaarloos\RvMail\Contracts\AudienceScopeResolver;

/**
 * Tijdelijke invulling zolang het aparte rollen- en permissieontwerp er niet is.
 *
 * Geeft iedereen volledig bereik en is dus ALLEEN bedoeld voor de workbench en
 * voor lokale ontwikkeling. De club-app moet dit binden aan een eigen resolver
 * vóór er in productie iets verstuurd wordt.
 */
final class UnrestrictedScopeResolver implements AudienceScopeResolver
{
    public function scopesFor(Authorizable $user): AudienceScopeSet
    {
        return AudienceScopeSet::all();
    }
}
