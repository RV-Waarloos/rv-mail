<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Audiences;

/**
 * Welke doelgroepen een gebruiker mag aanspreken, en met welke parameterwaarden.
 *
 * Een scope is een doelgroepsleutel met eventueel een lijst toegelaten waarden
 * per parameter. Geen beperking op een parameter betekent: alle waarden.
 *
 *   AudienceScopeSet::of([
 *       'afdeling' => ['afdeling_id' => [3, 7]],   // enkel deze twee afdelingen
 *       'team'     => ['team_id' => [12]],
 *       'distribution_list' => [],                  // elke lijst
 *   ]);
 */
final readonly class AudienceScopeSet
{
    /**
     * @param  array<string, array<string, list<int|string>>>  $scopes
     */
    private function __construct(
        private array $scopes,
        private bool $unrestricted,
    ) {}

    /** Beheerder, secretariaat, bestuur: geen beperking. */
    public static function all(): self
    {
        return new self([], true);
    }

    /** Een gewoon lid: geen enkele doelgroep. */
    public static function none(): self
    {
        return new self([], false);
    }

    /**
     * @param  array<string, array<string, list<int|string>>>  $scopes
     */
    public static function of(array $scopes): self
    {
        return new self($scopes, false);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function allows(string $audienceKey, array $params = []): bool
    {
        if ($this->unrestricted) {
            return true;
        }

        if (! array_key_exists($audienceKey, $this->scopes)) {
            return false;
        }

        foreach ($this->scopes[$audienceKey] as $parameter => $allowedValues) {
            if ($allowedValues === []) {
                continue;
            }

            // Een parameter waarvoor een beperking geldt, moet ook effectief
            // meegegeven zijn. Anders zou een leeg verzoek de beperking omzeilen.
            if (! array_key_exists($parameter, $params)) {
                return false;
            }

            $given = is_array($params[$parameter]) ? $params[$parameter] : [$params[$parameter]];

            foreach ($given as $value) {
                if (! in_array($value, $allowedValues, false)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<string> */
    public function audienceKeys(): array
    {
        return array_keys($this->scopes);
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    public function isEmpty(): bool
    {
        return ! $this->unrestricted && $this->scopes === [];
    }
}
