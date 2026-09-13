<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RvWaarloos\RvMail\Enums\MailCategory;
use RvWaarloos\RvMail\Mail\UnsubscribeLinkMail;
use RvWaarloos\RvMail\Models\CampaignRecipient;
use RvWaarloos\RvMail\Models\Unsubscribe;

/**
 * De uitschrijfpagina.
 *
 * Twee uitgangspunten die het ontwerp sturen. Ten eerste: de link heeft geen
 * vervaldatum en vereist geen login, want de procedure moet werkelijk eenvoudig
 * zijn, ook vanuit een mail van twee jaar oud. Ten tweede: uitschrijven is
 * categoriegebonden — wie het clubnieuws afzet moet de permanentie-oproepen
 * blijven krijgen.
 */
final class UnsubscribeController
{
    public function show(Request $request, string $recipient): View
    {
        $row = $this->recipient($recipient);

        /** @var view-string $view */
        $view = 'rv-mail::unsubscribe.show';

        return view($view, [
            'recipientUlid' => $recipient,
            'email' => $row?->email,
            'name' => $row?->name,
            'categories' => $this->optOutableCategories(),
            'current' => $this->currentOptOuts($row?->email),
            'confirmed' => $request->boolean('bevestigd'),
            'actionUrl' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, string $recipient): RedirectResponse
    {
        $row = $this->recipient($recipient);

        if ($row === null) {
            return redirect()->to($this->backUrl($request));
        }

        /** @var list<string> $keep */
        $keep = array_values(array_filter(
            (array) $request->input('categories', []),
            static fn (mixed $value): bool => is_string($value),
        ));

        foreach ($this->optOutableCategories() as $category) {
            $wantsMail = in_array($category->value, $keep, true);

            $wantsMail
                ? $this->resubscribe($row, $category)
                : $this->unsubscribe($row, $category);
        }

        return redirect()->to($this->backUrl($request));
    }

    /**
     * Vangnet voor een link waarvan de handtekening niet klopt. Vult iemand zijn
     * adres in, dan sturen we een verse link.
     */
    public function request(): View
    {
        /** @var view-string $view */
        $view = 'rv-mail::unsubscribe.request';

        return view($view, ['sent' => false]);
    }

    public function sendLink(Request $request): View
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        $row = $email === ''
            ? null
            : CampaignRecipient::query()
                ->where('email', $email)
                ->whereNotNull('ulid')
                ->latest('id')
                ->first();

        if ($row instanceof CampaignRecipient) {
            Mail::to($row->email)->send(new UnsubscribeLinkMail(
                url: URL::signedRoute(
                    (string) config('rv-mail.unsubscribe.route', 'rv-mail.unsubscribe'),
                    ['recipient' => $row->ulid],
                ),
                name: $row->name,
            ));
        }

        // Altijd dezelfde bevestiging tonen: of een adres bij ons bekend is,
        // is informatie die een willekeurige bezoeker niet hoeft te krijgen.
        /** @var view-string $view */
        $view = 'rv-mail::unsubscribe.request';

        return view($view, ['sent' => true]);
    }

    private function recipient(string $ulid): ?CampaignRecipient
    {
        return CampaignRecipient::query()->where('ulid', $ulid)->first();
    }

    /**
     * @return list<MailCategory>
     */
    private function optOutableCategories(): array
    {
        return array_values(array_filter(
            MailCategory::cases(),
            static fn (MailCategory $category): bool => $category->isOptOutable(),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function currentOptOuts(?string $email): array
    {
        if ($email === null) {
            return [];
        }

        return Unsubscribe::query()
            ->where('email', mb_strtolower($email))
            ->pluck('category')
            ->map(static fn (mixed $value): string => $value instanceof MailCategory ? $value->value : (string) $value)
            ->values()
            ->all();
    }

    private function unsubscribe(CampaignRecipient $row, MailCategory $category): void
    {
        Unsubscribe::query()->updateOrCreate(
            ['email' => mb_strtolower($row->email), 'category' => $category],
            [
                'member_id' => $row->member_id,
                'source' => 'self-service',
                'unsubscribed_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Opnieuw inschrijven mag: het bezwaarrecht is absoluut, maar het is geen
     * eenrichtingsverkeer. Wie zich bedenkt hoeft niemand te bellen.
     */
    private function resubscribe(CampaignRecipient $row, MailCategory $category): void
    {
        Unsubscribe::query()
            ->where('email', mb_strtolower($row->email))
            ->where('category', $category)
            ->delete();
    }

    private function backUrl(Request $request): string
    {
        $url = $request->fullUrl();

        return $url.(str_contains($url, '?') ? '&' : '?').'bevestigd=1';
    }
}
