<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Filament\Resources\DistributionLists\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class DistributionListForm
{
    /**
     * Namen die erop wijzen dat iemand een doelgroep aan het namaken is als
     * lijst. Niet blokkeren, wel waarschuwen.
     *
     * @var list<string>
     */
    private const array AFGELEIDE_NAMEN = [
        'alle leden', 'actieve leden', 'alle actieve', 'afdeling',
        'ploeg', 'team', 'iedereen', 'volledige club',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    TextInput::make('name')
                        ->label('Naam')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(static function (mixed $state, callable $set): void {
                            if (is_string($state)) {
                                $set('slug', Str::slug($state));
                            }
                        }),

                    TextInput::make('slug')
                        ->label('Sleutel')
                        ->helperText('Wordt gebruikt in code en configuratie. Laat staan tenzij je weet waarom.')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    // Dit is de fout die je binnen een jaar hebt: een handmatige
                    // lijst die zes maanden later niet meer klopt terwijl er wel
                    // naar verstuurd wordt.
                    Text::make(new HtmlString(
                        '<strong>Let op.</strong> Deze naam lijkt op een groep die het systeem zelf kan afleiden. '.
                        'Kies dan liever een doelgroep bij het opstellen van de mailing: die blijft vanzelf actueel, '.
                        'terwijl een lijst handmatig bijgewerkt moet worden.'
                    ))
                        ->color('warning')
                        ->visible(static fn (Get $get): bool => self::lijktOpDoelgroep($get('name')))
                        ->columnSpanFull(),

                    Textarea::make('description')
                        ->label('Waarvoor dient deze lijst')
                        ->helperText('Schrijf dit voor de collega die hem over twee jaar tegenkomt.')
                        ->rows(2)
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->label('Actief')
                        ->helperText('Een niet-actieve lijst blijft bestaan maar is niet kiesbaar bij een mailing.')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    private static function lijktOpDoelgroep(mixed $name): bool
    {
        if (! is_string($name) || $name === '') {
            return false;
        }

        $normalized = Str::lower($name);

        foreach (self::AFGELEIDE_NAMEN as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }
}
