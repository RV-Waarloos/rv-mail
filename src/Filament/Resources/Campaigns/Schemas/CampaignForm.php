<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\Campaigns\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
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
                        ->live()
                        // Parameters van de vorige keuze horen niet mee te
                        // verhuizen naar een andere doelgroep: een afdeling-id
                        // in een distributielijst levert stil een lege selectie op.
                        ->afterStateUpdated(static function (Set $set): void {
                            $set('audience_params', []);
                        }),

                    ...self::allAudienceParameterFields(),

                    Text::make(new HtmlString(
                        'Kies eerst een groep. Sommige groepen vragen daarna nog een keuze, '.
                            'zoals welke afdeling of welke lijst.'
                    ))
                        ->color('gray')
                        ->visible(static fn (Get $get): bool => ! is_string($get('audience_type')) || $get('audience_type') === '')
                        ->columnSpanFull(),

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

    /**
     * Alle parametervelden van alle doelgroepen tegelijk, elk zichtbaar en
     * verplicht bij de eigen doelgroep.
     *
     * Niet mooi, maar wel betrouwbaar. Een schema dat per render opnieuw
     * opgebouwd wordt, komt niet in de validatieboom van Filament terecht: het
     * veld toont wel een waarde maar de validator ziet null.
     *
     * @return list<Field>
     */
    private static function allAudienceParameterFields(): array
    {
        $fields = [];

        foreach (app(AudienceRegistry::class)->all() as $audience) {
            $key = $audience->key();

            foreach ($audience->parameterSchema() as $name => $spec) {
                $fields[] = self::buildField($name, $spec)
                    ->visible(static fn (Get $get): bool => $get('audience_type') === $key)
                    ->required(static fn (Get $get): bool => $spec['required'] && $get('audience_type') === $key);
            }
        }

        return $fields;
    }

    /**
     * @param  array{
     *     type: 'select'|'multiselect'|'text'|'number',
     *     label: string,
     *     required: bool,
     *     options?: array<int|string, string>,
     *     helper?: string,
     *     default?: int|string|null
     * }  $spec
     */
    private static function buildField(string $name, array $spec): Field
    {
        $options = $spec['options'] ?? [];

        $field = match ($spec['type']) {
            'select' => Select::make("param_{$name}")
                ->options($options)
                ->searchable(count($options) > 10)
                ->native(false),

            'multiselect' => Select::make("param_{$name}")
                ->options($options)
                ->multiple()
                ->searchable(count($options) > 10),

            'number' => TextInput::make("param_{$name}")->numeric(),

            default => TextInput::make("param_{$name}"),
        };

        return $field
            ->label($spec['label'])
            ->helperText($spec['helper'] ?? null)
            ->default($spec['default'] ?? null);
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

    /**
     * Vertaalt de platte param_-velden naar de audience_params-array.
     *
     * De omweg is nodig omdat Filament een geneste statepath niet betrouwbaar
     * bindt aan een schema dat per render opnieuw opgebouwd wordt.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function collectAudienceParams(array $data): array
    {
        $params = [];

        foreach ($data as $key => $value) {
            if (! str_starts_with($key, 'param_')) {
                continue;
            }

            unset($data[$key]);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $params[substr($key, 6)] = $value;
        }

        $data['audience_params'] = $params;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function expandAudienceParams(array $data): array
    {
        $params = $data['audience_params'] ?? [];

        if (is_array($params)) {
            foreach ($params as $name => $value) {
                $data['param_'.$name] = $value;
            }
        }

        return $data;
    }
}
