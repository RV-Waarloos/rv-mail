<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use RvWaarloos\RvMail\Campaigns\CampaignComposer;
use RvWaarloos\RvMail\Campaigns\CampaignDispatcher;
use RvWaarloos\RvMail\Campaigns\TestSender;
use RvWaarloos\RvMail\Enums\CampaignStatus;
use RvWaarloos\RvMail\Enums\RecipientStatus;
use RvWaarloos\RvMail\Models\Campaign;
use RvWaarloos\RvMail\Support\QuotaGuard;
use Throwable;

/**
 * De acties op een campagne, als losse fabrieken.
 *
 * Ze staan hier en niet in de resource, zodat de lijstweergave, de detailpagina
 * en een eventuele relation manager exact hetzelfde gedrag krijgen. Diezelfde
 * logica op drie plaatsen herhalen is hoe de knop op de ene plek wél de
 * goedkeuring controleert en op de andere niet.
 */
final class CampaignActions
{
    public static function compose(): Action
    {
        return Action::make('compose')
            ->label('Samenstellen')
            ->icon('heroicon-o-users')
            ->color('gray')
            ->visible(static fn (Campaign $record): bool => $record->status->canCompose())
            ->authorize(static fn (Campaign $record): bool => auth()->user()?->can('rv-mail.campaign.create') ?? false)
            ->action(static function (Campaign $record): void {
                try {
                    $result = app(CampaignComposer::class)->compose($record, auth()->user());
                } catch (Throwable $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Samenstellen mislukt')
                        ->body($exception->getMessage())
                        ->send();

                    return;
                }

                $body = "{$result->sendable} bestemmelingen.";

                if ($result->skippedTotal() > 0) {
                    $body .= " {$result->skippedTotal()} overgeslagen — zie het tabblad Bestemmelingen voor de reden.";
                }

                Notification::make()->success()->title('Samengesteld')->body($body)->send();
            });
    }

    /**
     * De belangrijkste knop. Toont vóór het verzenden wie je bereikt, wie niet
     * en waarom, en wat het kost aan quotum.
     */
    public static function dispatch(): Action
    {
        return Action::make('dispatch')
            ->label('Verzenden')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->visible(static fn (Campaign $record): bool => $record->status->canDispatch())
            ->authorize(static fn (Campaign $record): bool => auth()->user()?->can('rv-mail.campaign.send') ?? false)
            ->modalHeading('Controleer voor je verzendt')
            ->modalSubmitActionLabel('Definitief verzenden')
            ->modalWidth('2xl')
            ->schema(static fn (Campaign $record): array => self::preflightSchema($record))
            ->requiresConfirmation(false)
            ->action(static function (Campaign $record): void {
                try {
                    $batches = app(CampaignDispatcher::class)->dispatch($record, auth()->user());
                } catch (Throwable $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Verzenden geblokkeerd')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Verzending gestart')
                    ->body("Verdeeld over {$batches} batch(es). De voortgang verschijnt hieronder.")
                    ->send();
            });
    }

    /**
     * @return list<Component>
     */
    private static function preflightSchema(Campaign $record): array
    {
        $quota = QuotaGuard::forCurrentPeriod();
        $sendable = $record->recipients()->sendable()->count();
        $skipped = $record->recipients()->where('status', RecipientStatus::Suppressed)->count();

        $lines = [
            Text::make(new HtmlString("Deze mailing bereikt <strong>{$sendable}</strong> bestemmelingen.")),
        ];

        if ($skipped > 0) {
            $lines[] = Text::make(new HtmlString("<strong>{$skipped}</strong> worden overgeslagen. De reden staat per persoon bij Bestemmelingen."))
                ->color('warning');
        }

        $used = $quota->usedInPeriod();
        $limit = $quota->limit();
        $after = $used + $sendable;

        $lines[] = Text::make(new HtmlString(
            "Verbruikt deze periode: <strong>{$used} / {$limit}</strong>. Na verzending: <strong>{$after}</strong>. ".
                "Resterend voor transactionele mail: <strong>{$quota->transactionalReserve()}</strong>."
        ));

        if (! $quota->canSendBulk($sendable)) {
            $lines[] = Text::make(
                'Er is onvoldoende quotum. Verzenden kost dan $1,50 per 1.000 extra mails.'
            )->color('danger');
        }

        if ($record->requiresApproval() && ! $record->isApproved()) {
            $lines[] = Text::make(
                'Deze mailing bereikt alle leden en moet eerst goedgekeurd worden door iemand anders dan de opsteller.'
            )->color('danger');
        }

        // Het verschil tussen de strategieën tonen zodra er gedeelde adressen
        // zijn, zodat samenvoegen een geinformeerde keuze is en geen verstopte
        // instelling.
        $shared = $record->recipients()
            ->selectRaw('email, count(*) as aantal')
            ->groupBy('email')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($shared->isNotEmpty()) {
            $addresses = $shared->count();
            $members = (int) $shared->sum('aantal');
            $savings = $members - $addresses;

            $lines[] = Text::make(
                "{$addresses} adres(sen) worden gedeeld door {$members} leden. ".
                    "Samenvoegen per adres bespaart {$savings} mail(s)."
            )->color('gray');
        }

        return $lines;
    }

    public static function sendTest(): Action
    {
        return Action::make('sendTest')
            ->label('Testverzending')
            ->icon('heroicon-o-beaker')
            ->color('gray')
            ->authorize(static fn (): bool => auth()->user()?->can('rv-mail.campaign.create') ?? false)
            ->schema([
                TextInput::make('email')
                    ->label('Naar')
                    ->email()
                    ->required()
                    ->default(static fn (): ?string => auth()->user()?->getAttribute('email')),
            ])
            ->action(static function (Campaign $record, array $data): void {
                try {
                    app(TestSender::class)->send($record, (string) $data['email']);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Testverzending mislukt')
                        ->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title('Test verstuurd')
                    ->body('Kijk in je inbox hoe de mail er bij een lid uitziet.')
                    ->send();
            });
    }

    /**
     * Goedkeuren door iemand anders dan de opsteller. Zonder die controle is de
     * vier-ogen-stap een knop die je zelf indrukt.
     */
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Goedkeuren')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Je bevestigt dat deze mailing naar alle leden mag vertrekken.')
            ->visible(static fn (Campaign $record): bool => $record->requiresApproval() && ! $record->isApproved())
            ->authorize(static fn (Campaign $record): bool => ($auth = auth()->user()) !== null
                && $auth->can('rv-mail.campaign.approve')
                && $record->canBeApprovedBy($auth->getAuthIdentifier()))
            ->action(static function (Campaign $record): void {
                $record->forceFill([
                    'approved_by' => auth()->id(),
                    'approved_at' => Carbon::now(),
                ])->save();

                Notification::make()->success()->title('Goedgekeurd')->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Annuleren')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Batches die al vertrokken zijn, kunnen niet teruggehaald worden.')
            ->visible(static fn (Campaign $record): bool => $record->status->canCancel())
            ->authorize(static fn (Campaign $record): bool => auth()->user()?->can('rv-mail.campaign.send') ?? false)
            ->action(static function (Campaign $record): void {
                $record->forceFill(['status' => CampaignStatus::Cancelled])->save();

                Notification::make()->warning()->title('Geannuleerd')->send();
            });
    }

    public static function retryFailed(): Action
    {
        return Action::make('retryFailed')
            ->label('Mislukte opnieuw proberen')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(static fn (Campaign $record): bool => $record->count_failed > 0)
            ->authorize(static fn (Campaign $record): bool => auth()->user()?->can('rv-mail.campaign.send') ?? false)
            ->action(static function (Campaign $record): void {
                // Terug op pending zetten en hersamenstellen overslaan: het
                // snapshot blijft zoals het was, alleen de mislukten gaan mee.
                $count = $record->recipients()
                    ->where('status', RecipientStatus::Failed)
                    ->update([
                        'status' => RecipientStatus::Pending,
                        'batch_id' => null,
                        'failure_reason' => null,
                    ]);

                $record->forceFill([
                    'status' => CampaignStatus::Composed,
                    'count_failed' => 0,
                ])->save();

                Notification::make()->success()
                    ->title("{$count} bestemmeling(en) klaargezet")
                    ->body('Gebruik Verzenden om ze opnieuw te proberen.')
                    ->send();
            });
    }
}
