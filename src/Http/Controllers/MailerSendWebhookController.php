<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RvWaarloos\RvMail\Jobs\ProcessWebhookEvent;
use RvWaarloos\RvMail\Models\WebhookDelivery;
use RvWaarloos\RvMail\Webhooks\WebhookPayload;

/**
 * Doet het absolute minimum en geeft meteen 202 terug.
 *
 * MailerSend verwacht een snel antwoord; parsing en verwerking horen niet in de
 * request. De tabel met de unieke index op het event-id is de wachtrij, met
 * idempotentie ingebouwd.
 */
final class MailerSendWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->json()->all();

        $payload = WebhookPayload::fromArray($data);

        // insertOrIgnore laat de database de gelijktijdigheid oplossen: twee
        // identieke leveringen tegelijk mogen niet tot twee rijen leiden.
        $inserted = DB::connection('central')->table('mail_webhook_deliveries')->insertOrIgnore([
            'ms_event_id' => $payload->eventId,
            'type' => $payload->type,
            'signature_valid' => true,
            'raw_payload' => json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        if ($inserted === 0) {
            // Al eerder ontvangen. Netjes bevestigen, anders blijft MailerSend
            // opnieuw proberen.
            return response()->json(['message' => 'Already received.'], 202);
        }

        $delivery = WebhookDelivery::query()
            ->where('ms_event_id', $payload->eventId)
            ->first();

        if ($delivery instanceof WebhookDelivery) {
            ProcessWebhookEvent::dispatch($delivery->id)
                ->onQueue((string) config('rv-mail.queue', 'mail'));
        }

        return response()->json(['message' => 'Accepted.'], 202);
    }
}
