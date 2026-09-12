<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Audiences;

use Illuminate\Contracts\Auth\Access\Authorizable;
use RvWaarloos\RvMail\Contracts\Audience;
use RvWaarloos\RvMail\Contracts\AudienceScopeResolver;

/**
 * Basisimplementatie die de autorisatie afhandelt via de scope resolver.
 *
 * Een doelgroep hoeft daardoor alleen nog te weten hoe ze bestemmelingen
 * ophaalt; wie haar mag gebruiken is een vraag voor de host-app.
 */
abstract class AbstractAudience implements Audience
{
    public function __construct(
        protected readonly AudienceScopeResolver $scopes,
    ) {}

    /** @return array<string, array{type: string, label: string, required: bool}> */
    public function parameterSchema(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function authorize(Authorizable $user, array $params): bool
    {
        return $this->scopes->scopesFor($user)->allows($this->key(), $params);
    }
}
