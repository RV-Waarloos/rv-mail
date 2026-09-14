<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Audiences;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Campaigns\RecipientCandidate;
use RvWaarloos\RvMail\Contracts\MemberDirectory;
use RvWaarloos\RvMail\Models\DistributionList;
use RvWaarloos\RvMail\Models\DistributionListMember;

/**
 * De enige doelgroep die rv-mail zelf meebrengt, omdat distributielijsten ook
 * in dit package leven.
 *
 * Bedoeld voor groepen die het systeem niet kan afleiden: een evenementenploeg,
 * externe contacten. Alles wat uit de ledenadministratie volgt hoort een eigen
 * doelgroep te zijn, want die blijft vanzelf actueel.
 */
final class DistributionListAudience extends AbstractAudience
{
    public function key(): string
    {
        return 'distribution_list';
    }

    public function label(): string
    {
        return 'Distributielijst';
    }

    /**
     * @return array<string, array{
     *     type: 'select'|'multiselect'|'text'|'number',
     *     label: string,
     *     required: bool,
     *     options?: array<int|string, string>,
     *     helper?: string,
     *     default?: int|string|null
     * }>
     */
    public function parameterSchema(): array
    {
        return [
            'list_id' => [
                'type' => 'select',
                'label' => 'Lijst',
                'required' => true,
                'options' => $this->activeLists(),
                'helper' => 'Alleen actieve lijsten. Groepen die uit de ledenadministratie volgen kies je beter als eigen doelgroep.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return iterable<RecipientCandidate>
     */
    public function resolve(array $params): iterable
    {
        $list = $this->find($params);

        if (! $list instanceof DistributionList) {
            return;
        }

        $this->markUsed($list);

        $directory = app(MemberDirectory::class);

        foreach ($list->members as $member) {
            $candidate = $member->member_id !== null
                ? $this->fromMember($directory, $member)
                : $this->fromExternal($member);

            if ($candidate === null) {
                continue;
            }

            yield $candidate->withPersonalization(['lijst' => $list->name]);
        }
    }

    /**
     * Een clublid krijgt dezelfde personalisatievelden als bij een
     * ledendoelgroep. Anders zou dezelfde persoon in de ene mailing met naam
     * aangesproken worden en in de andere niet, afhankelijk van hoe de opsteller
     * hem selecteerde.
     */
    private function fromMember(MemberDirectory $directory, DistributionListMember $member): ?RecipientCandidate
    {
        $memberId = $member->member_id;

        if ($memberId === null) {
            return null;
        }

        $candidate = $directory->candidateFor($memberId);

        if ($candidate instanceof RecipientCandidate) {
            return $candidate;
        }

        // De ledenbron kent dit lid niet of levert geen kandidaat. Terugvallen
        // op wat we zelf weten is beter dan de persoon stil overslaan.
        $email = $directory->emailFor($memberId);

        if ($email === null || $email === '') {
            return null;
        }

        return new RecipientCandidate(
            email: $email,
            name: $directory->labelFor($memberId),
            memberId: $memberId,
        );
    }

    private function fromExternal(DistributionListMember $member): ?RecipientCandidate
    {
        $email = $member->email;

        if ($email === null || $email === '') {
            return null;
        }

        $name = $member->name;

        return new RecipientCandidate(
            email: $email,
            name: $name,
            memberId: null,
            personalization: [
                // Externen hebben geen ledenfiche, dus de naam uit de lijst is
                // alles wat we hebben. De aanspreking valt terug op "beste"
                // zodat een mail nooit met een lege begroeting vertrekt.
                'voornaam' => $this->firstName($name),
                'achternaam' => $this->lastName($name),
                'aanspreking' => $this->firstName($name) ?? 'beste',
            ],
        );
    }

    private function firstName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($name));

        return $parts === false || $parts === [] ? null : $parts[0];
    }

    private function lastName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($name));

        if ($parts === false || count($parts) < 2) {
            return null;
        }

        array_shift($parts);

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function describe(array $params): string
    {
        $list = $this->find($params, withMembers: false);

        return $list instanceof DistributionList
            ? 'Distributielijst: '.$list->name
            : 'Distributielijst (nog niet gekozen)';
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function find(array $params, bool $withMembers = true): ?DistributionList
    {
        $listId = $params['list_id'] ?? null;

        if (! is_int($listId) && ! is_string($listId)) {
            return null;
        }

        return DistributionList::query()
            ->where('is_active', true)
            ->when(
                $withMembers,
                static fn (Builder $query): Builder => $query->with('members'),
            )
            ->find($listId);
    }

    /** @return array<int|string, string> */
    private function activeLists(): array
    {
        return DistributionList::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Zodat het overzicht kan tonen welke lijsten al lang stilliggen. Een lijst
     * die niemand meer gebruikt is een lijst die niemand meer onderhoudt.
     */
    private function markUsed(DistributionList $list): void
    {
        $list->forceFill(['last_used_at' => Carbon::now()])->saveQuietly();
    }
}
