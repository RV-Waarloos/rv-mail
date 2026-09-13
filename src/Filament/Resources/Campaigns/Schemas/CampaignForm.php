<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use RvWaarloos\RvMail\Audiences\AudienceRegistry;
use RvWaarloos\RvMail\Contracts\Audience;
use RvWaarloos\RvMail\Enums\DedupStrategy;
use RvWaarloos\RvMail\Enums\MailCategory;

final class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Inhoud')
                ->schema([
                    TextInput::make('name')
                        ->label('Interne naam')
                        ->helperText('Alleen zichtbaar in dit scherm.')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('subject_template')
                        ->label('Onderwerp')
                        ->helperText('Gebruik {{aanspreking}} of {{voornaam}} voor personalisatie.')
                        ->required()
                        ->maxLength(500),

                    MarkdownEditor::make('body_markdown')
                        ->label('Bericht')
                        ->helperText('Markdown. Verwijs naar bestanden met een link naar de site; bijlagen worden niet verstuurd.')
                        ->required()
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Doelgroep')
                ->schema([
                    Select::make('category')
                        ->label('Soort mail')
                        ->options(self::categoryOptions())
                        ->helperText(static fn (Get $get): string => self::categoryHint($get('category')))
                        ->required()
                        ->live()
                        ->default(MailCategory::Nieuws->value),

                    Select::make('audience_type')
                        ->label('Groep')
                        ->options(self::audienceOptions())
                        ->required()
                        ->live(),

                    Select::make('dedup_strategy')
                        ->label('Gezinnen met een gedeeld adres')
                        ->options([
                            DedupStrategy::PerMember->value => DedupStrategy::PerMember->label().' (standaard)',
                            DedupStrategy::PerEmail->value => DedupStrategy::PerEmail->label(),
                        ])
                        ->helperText(static fn (Get $get): string => DedupStrategy::tryFrom((string) $get('dedup_strategy'))?->description() ?? '')
                        ->default(DedupStrategy::PerMember->value)
                        ->live()
                        ->required(),
                ])
                ->columns(2),

            Section::make('Verzending')
                ->schema([
                    TextInput::make('reply_to')
                        ->label('Antwoorden naar')
                        ->email()
                        ->helperText('Leeg laten om het secretariaat te gebruiken.')
                        ->default(static fn (): ?string => config('rv-mail.reply_to')),

                    Checkbox::make('track_clicks')
                        ->label('Klikken registreren')
                        ->helperText('Links worden dan herschreven naar een MailerSend-domein. Openregistratie gebeurt nooit.')
                        ->default(false),
                ])
                ->columns(2)
                ->collapsed(),
        ]);
    }

    /** @return array<string, string> */
    private static function categoryOptions(): array
    {
        $options = [];

        foreach (MailCategory::cases() as $category) {
            // Transactionele mail wordt niet als campagne opgesteld: die komt
            // uit de applicatie zelf en wordt automatisch gelogd.
            if ($category->isTransactional()) {
                continue;
            }

            $options[$category->value] = $category->label();
        }

        return $options;
    }

    private static function categoryHint(mixed $value): string
    {
        $category = is_string($value) ? MailCategory::tryFrom($value) : null;

        if (! $category instanceof MailCategory) {
            return '';
        }

        $basis = $category->legalBasis()->label().' ('.$category->legalBasis()->article().')';

        return $category->isOptOutable()
            ? $basis.'. Bevat een uitschrijflink; leden die zich afmeldden krijgen deze mail niet.'
            : $basis.'. Geen uitschrijflink, en niemand wordt overgeslagen op basis van een afmelding.';
    }

    /** @return array<string, string> */
    private static function audienceOptions(): array
    {
        $user = auth()->user();
        $registry = app(AudienceRegistry::class);

        $available = $user === null
            ? $registry->all()
            : $registry->availableFor($user);

        return array_map(
            static fn (Audience $audience): string => $audience->label(),
            $available,
        );
    }
}
