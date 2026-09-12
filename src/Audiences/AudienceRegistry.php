<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Audiences;

use Illuminate\Contracts\Auth\Access\Authorizable;
use RvWaarloos\RvMail\Contracts\Audience;
use RvWaarloos\RvMail\Exceptions\UnknownAudience;

/**
 * Houdt bij welke doelgroepen bestaan.
 *
 * De club-app registreert hier haar eigen implementaties, zodat rv-mail de
 * keuzelijst kan tonen zonder de domeinen te kennen.
 */
final class AudienceRegistry
{
    /** @var array<string, Audience> */
    private array $audiences = [];

    public function register(Audience $audience): void
    {
        $this->audiences[$audience->key()] = $audience;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->audiences);
    }

    /** @throws UnknownAudience */
    public function get(string $key): Audience
    {
        return $this->audiences[$key] ?? throw UnknownAudience::forKey($key);
    }

    /** @return array<string, Audience> */
    public function all(): array
    {
        return $this->audiences;
    }

    /**
     * Enkel de doelgroepen die deze gebruiker überhaupt mag aanspreken. Bedoeld
     * voor de keuzelijst in de opsteller — de echte controle gebeurt opnieuw
     * bij het samenstellen én bij het verzenden.
     *
     * @return array<string, Audience>
     */
    public function availableFor(Authorizable $user): array
    {
        return array_filter(
            $this->audiences,
            static fn (Audience $audience): bool => $audience->authorize($user, []),
        );
    }
}
