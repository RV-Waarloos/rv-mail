<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Carbon;
use RvWaarloos\RvMail\Audiences\AudienceRegistry;
use RvWaarloos\RvMail\Campaigns\CampaignComposer;
use RvWaarloos\RvMail\Campaigns\RecipientCandidate;
use RvWaarloos\RvMail\Contracts\Audience;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\DedupStrategy;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Enums\SkipReason;
use RvWaarloos\RvMail\Enums\SuppressionReason;
use RvWaarloos\RvMail\Exceptions\CampaignNotComposable;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Models\Suppression;
use RvWaarloos\RvMail\Models\Unsubscribe;

/**
 * Een doelgroep met een vaste lijst, zodat de composer getest kan worden zonder
 * ledenmodel — precies waarom het package geen Member kent.
 */
function fakeAudience(array $candidates): Audience
{
    return new class($candidates) implements Audience
    {
        public function __construct(private readonly array $candidates) {}

        public function key(): string
        {
            return 'test_audience';
        }

        public function label(): string
        {
            return 'Testdoelgroep';
        }

        public function parameterSchema(): array
        {
            return [];
        }

        public function resolve(array $params): iterable
        {
            return $this->candidates;
        }

        public function authorize(Authorizable $user, array $params): bool
        {
            return true;
        }
    };
}

function makeCampaign(MailCategory $category = MailCategory::Nieuws, DedupStrategy $strategy = DedupStrategy::PerMember): Campaign
{
    return Campaign::query()->create([
        'name' => 'Testmailing',
        'category' => $category,
        'subject_template' => 'Nieuws van {{aanspreking}}',
        'body_markdown' => 'Dag {{aanspreking}}',
        'from_email' => 'info@mail.rvwaarloos.be',
        'from_name' => 'RV Waarloos',
        'audience_type' => 'test_audience',
        'audience_params' => [],
        'dedup_strategy' => $strategy,
        'status' => CampaignStatus::Draft,
    ]);
}

beforeEach(function (): void {
    $this->composer = fn (array $candidates): CampaignComposer => tap(
        app(CampaignComposer::class),
        function () use ($candidates): void {
            app(AudienceRegistry::class)->register(fakeAudience($candidates));
        },
    );
});

it('schrijft een snapshot weg en markeert de campagne als samengesteld', function (): void {
    $campaign = makeCampaign();

    $result = ($this->composer)([
        new RecipientCandidate('jan@telenet.be', 'Jan Peeters', 1),
        new RecipientCandidate('mieke@telenet.be', 'Mieke Peeters', 2),
    ])->compose($campaign);

    expect($result->sendable)->toBe(2)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Composed)
        ->and($campaign->fresh()->recipients_sendable)->toBe(2);
});

it('bewaart overgeslagen bestemmelingen met een reden in plaats van ze weg te laten', function (): void {
    Suppression::query()->create([
        'email' => 'bounce@telenet.be',
        'reason' => SuppressionReason::HardBounce,
        'suppressed_at' => Carbon::now(),
    ]);

    $campaign = makeCampaign();

    $result = ($this->composer)([
        new RecipientCandidate('jan@telenet.be', 'Jan Peeters', 1),
        new RecipientCandidate('bounce@telenet.be', 'Piet Janssens', 2),
        new RecipientCandidate('', 'Zonder Adres', 3),
        new RecipientCandidate('weg@telenet.be', 'Weg Gegaan', 4, anonymized: true),
    ])->compose($campaign);

    expect($result->sendable)->toBe(1)
        ->and($result->skippedFor(SkipReason::Suppressed))->toBe(1)
        ->and($result->skippedFor(SkipReason::Anonymized))->toBe(1);

    // Het adresloze lid verdwijnt al bij de deduplicatie, dus die rij bestaat
    // niet; de rest blijft zichtbaar met status suppressed.
    expect($campaign->recipients()->where('status', RecipientStatus::Suppressed)->count())->toBe(2);
});

it('respecteert een uitschrijving voor clubnieuws', function (): void {
    Unsubscribe::query()->create([
        'email' => 'stop@telenet.be',
        'category' => MailCategory::Nieuws,
        'unsubscribed_at' => Carbon::now(),
    ]);

    $result = ($this->composer)([
        new RecipientCandidate('stop@telenet.be', 'Stop Ermee', 1),
        new RecipientCandidate('door@telenet.be', 'Doe Voort', 2),
    ])->compose(makeCampaign(MailCategory::Nieuws));

    expect($result->sendable)->toBe(1)
        ->and($result->skippedFor(SkipReason::Unsubscribed))->toBe(1);
});

it('negeert een uitschrijving bij operationele mail', function (): void {
    // Operationele mail steunt op de lidmaatschapsovereenkomst en is niet
    // uitschrijfbaar: wie uitgeschreven is voor nieuws moet nog steeds horen
    // dat de training niet doorgaat.
    Unsubscribe::query()->create([
        'email' => 'stop@telenet.be',
        'category' => MailCategory::Nieuws,
        'unsubscribed_at' => Carbon::now(),
    ]);

    $result = ($this->composer)([
        new RecipientCandidate('stop@telenet.be', 'Stop Ermee', 1),
    ])->compose(makeCampaign(MailCategory::Operationeel));

    expect($result->sendable)->toBe(1);
});

it('is idempotent: hersamenstellen vervangt het snapshot', function (): void {
    $campaign = makeCampaign();
    $candidates = [new RecipientCandidate('jan@telenet.be', 'Jan Peeters', 1)];

    ($this->composer)($candidates)->compose($campaign);
    ($this->composer)($candidates)->compose($campaign->fresh());

    expect($campaign->recipients()->count())->toBe(1);
});

it('weigert samen te stellen wanneer de campagne al vertrokken is', function (): void {
    $campaign = makeCampaign();
    $campaign->forceFill(['status' => CampaignStatus::Sent])->save();

    ($this->composer)([])->compose($campaign);
})->throws(CampaignNotComposable::class);

it('rapporteert de gezinsimpact zodat de keuze geïnformeerd is', function (): void {
    $result = ($this->composer)([
        new RecipientCandidate('gezin@telenet.be', 'Jan Peeters', 1),
        new RecipientCandidate('gezin@telenet.be', 'Mieke Peeters', 2),
        new RecipientCandidate('los@telenet.be', 'Sofie Willems', 3),
    ])->compose(makeCampaign());

    expect($result->householdImpact['shared_addresses'])->toBe(1)
        ->and($result->householdImpact['savings'])->toBe(1)
        // Standaard krijgt elk lid zijn eigen bericht.
        ->and($result->sendable)->toBe(3);
});
