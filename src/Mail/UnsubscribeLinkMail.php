<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Verse uitschrijflink, voor wie op een verminkte of verouderde handtekening
 * botste.
 *
 * Transactioneel van aard: het lid heeft er zelf om gevraagd.
 */
final class UnsubscribeLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $url,
        public readonly ?string $name = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('rv-mail::mail.unsubscribe_link_subject'),
        );
    }

    public function content(): Content
    {
        /** @var view-string $view */
        $view = 'rv-mail::mail.unsubscribe-link';

        return new Content(view: $view);
    }
}
