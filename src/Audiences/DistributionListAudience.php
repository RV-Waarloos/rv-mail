<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Audiences;

use RvWaarloos\RvMail\Campaigns\RecipientCandidate;
use RvWaarloos\RvMail\Models\DistributionList;

/**
 * De enige doelgroep die rv-mail zelf meebrengt, omdat distributielijsten ook
 * in dit package leven.
 *
 * Externe bestemmelingen (scheidsrechters, bezoekende clubs) lopen uitsluitend
 * via deze weg. AdHocAudience selecteert uit de ledendatabank en heeft bewust
 * geen vrij tekstveld voor e-mailadressen: dat zou de achterdeur zijn waarlangs
 * het systeem alsnog een algemene mailclient wordt.
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

    /** @return array<string, array{type: string, label: string, required: bool}> */
    public function parameterSchema(): array
    {
        return [
            'list_id' => [
                'type' => 'select',
                'label' => 'Lijst',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return iterable<RecipientCandidate>
     */
    public function resolve(array $params): iterable
    {
        $listId = $params['list_id'] ?? null;

        if (! is_int($listId) && ! is_string($listId)) {
            return [];
        }

        $list = DistributionList::query()
            ->where('is_active', true)
            ->with('members')
            ->find($listId);

        if (! $list instanceof DistributionList) {
            return [];
        }

        foreach ($list->members as $member) {
            $email = $member->email;

            if ($email === null || $email === '') {
                continue;
            }

            yield new RecipientCandidate(
                email: $email,
                name: $member->name,
                memberId: $member->member_id,
                personalization: [
                    'lijst' => $list->name,
                ],
            );
        }
    }
}
