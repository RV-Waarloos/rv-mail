<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Enums;

/**
 * De MailerSend-activiteiten waarop we ons abonneren.
 *
 * activity.opened en activity.opened_unique ontbreken bewust: openregistratie
 * wordt op geen enkele mailing gebruikt, dus er valt niets te ontvangen.
 * activity.clicked (niet-uniek) valt af omdat het volume geeft zonder inzicht.
 */
enum MailEventType: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case SoftBounced = 'soft_bounced';
    case HardBounced = 'hard_bounced';
    case ClickedUnique = 'clicked_unique';
    case SpamComplaint = 'spam_complaint';
    case Unsubscribed = 'unsubscribed';

    public function webhookType(): string
    {
        return 'activity.'.$this->value;
    }

    public static function fromWebhookType(string $type): ?self
    {
        return self::tryFrom((string) preg_replace('/^activity\./', '', $type));
    }

    /** @return list<string> */
    public static function subscribedWebhookTypes(): array
    {
        return array_map(
            static fn (self $case): string => $case->webhookType(),
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Verzonden',
            self::Delivered => 'Afgeleverd',
            self::SoftBounced => 'Tijdelijk geweigerd',
            self::HardBounced => 'Definitief geweigerd',
            self::ClickedUnique => 'Link aangeklikt',
            self::SpamComplaint => 'Als spam gemarkeerd',
            self::Unsubscribed => 'Uitgeschreven',
        };
    }

    public function toRecipientStatus(): ?RecipientStatus
    {
        return match ($this) {
            self::Sent => RecipientStatus::Sent,
            self::Delivered => RecipientStatus::Delivered,
            self::SoftBounced => RecipientStatus::SoftBounced,
            self::HardBounced => RecipientStatus::HardBounced,
            default => null,
        };
    }

    /** Events die het adres meteen op de suppressielijst zetten. */
    public function toSuppressionReason(): ?SuppressionReason
    {
        return match ($this) {
            self::HardBounced => SuppressionReason::HardBounce,
            self::SpamComplaint => SuppressionReason::SpamComplaint,
            self::Unsubscribed => SuppressionReason::Unsubscribe,
            default => null,
        };
    }
}
