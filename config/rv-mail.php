<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Schema-eigenaarschap
    |--------------------------------------------------------------------------
    |
    | De mailtabellen leven in de gedeelde rv_central database. Net als bij
    | rv-core mag exact één applicatie de migraties draaien. Zet deze vlag
    | dus enkel in de app die eigenaar is van het centrale schema.
    |
    */

    'owns_schema' => env('RV_MAIL_OWNS_SCHEMA', false),

    /*
    |--------------------------------------------------------------------------
    | Afzender
    |--------------------------------------------------------------------------
    |
    | `from` is altijd de clubidentiteit, nooit een privépersoon. De opsteller
    | kan `reply_to` per campagne overschrijven wanneer een gericht antwoord
    | logisch is.
    |
    */

    'from' => [
        'address' => env('RV_MAIL_FROM_ADDRESS', 'info@mail.rvwaarloos.be'),
        'name' => env('RV_MAIL_FROM_NAME', 'RV Waarloos'),
    ],

    'reply_to' => env('RV_MAIL_REPLY_TO', 'secretariaat@rvwaarloos.be'),

    /*
    |--------------------------------------------------------------------------
    | MailerSend
    |--------------------------------------------------------------------------
    |
    | `dry_run` vervangt het transport door een fake die alles logt en niets
    | verstuurt. Zet dit aan op lokaal en staging zodat je geen credits
    | verbrandt tijdens het ontwikkelen.
    |
    */

    'mailersend' => [
        'api_key' => env('MAILERSEND_API_KEY'),
        'webhook_secret' => env('RV_MAIL_WEBHOOK_SECRET'),
        'dry_run' => env('RV_MAIL_DRY_RUN', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue en throttling
    |--------------------------------------------------------------------------
    |
    | Er is bewust geen gedeelde rate limiter. Elke batch krijgt bij het
    | inplannen een absoluut verzendmoment; de spreiding zit dus in de
    | tijdstempels zelf en blijft correct ongeacht het aantal workers.
    |
    | Het Hobby plan staat 10 bulk-requests per minuut toe. Met 8 seconden
    | tussenruimte zit je op 7,5 per minuut.
    |
    */

    'queue' => env('RV_MAIL_QUEUE', 'mail'),

    'throttle' => [
        'seconds_between_batches' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Batchgrootte
    |--------------------------------------------------------------------------
    |
    | MailerSend staat 500 berichten en 50 MB per request toe. Beide grenzen
    | worden bewaakt: 500 volledig gerenderde mails kunnen die 50 MB naderen.
    |
    */

    'batch' => [
        'max_messages' => 100,
        'max_payload_bytes' => 20 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotum
    |--------------------------------------------------------------------------
    |
    | MailerSend telt per rollend venster van 30 dagen vanaf de
    | abonnementsdatum, niet per kalendermaand, en biedt geen API om het
    | verbruik op te vragen. Daarom een eigen ledger.
    |
    | `transactional_reserve` is onaantastbaar voor bulkcampagnes, zodat een
    | enthousiaste mailing nooit het paswoordherstel kan blokkeren.
    |
    */

    'quota' => [
        'monthly_emails' => 5_000,
        'billing_period_start_day' => (int) env('RV_MAIL_BILLING_DAY', 1),
        'transactional_reserve' => 1_000,
        'daily_api_requests' => 1_000,
        'warn_at_percentage' => 80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Goedkeuring
    |--------------------------------------------------------------------------
    |
    | Een tweede paar ogen is vereist bij een clubbrede mailing. De drempel op
    | aantal bestemmelingen staat uit, maar het mechanisme zit er: als de
    | grootste afdeling ooit te groot wordt, is het een configregel.
    |
    | `approver_must_differ` is de belangrijkste regel. Wie send én approve
    | heeft, kan anders zijn eigen mailing goedkeuren.
    |
    */

    'approval' => [
        'required_for_audiences' => ['active_members'],
        'required_from_recipients' => null,
        'approver_must_differ' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rem tegen ongelukken
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'campaigns_per_user_per_day' => 10,
        'confirm_above_recipients' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | Openregistratie staat uit en is bewust niet instelbaar. Een trackingpixel
    | is toegang tot het toestel van de ontvanger en valt onder hetzelfde
    | toestemmingsregime als cookies; de opbrengst voor een club weegt daar
    | niet tegen op, zeker niet bij een jeugdwerking.
    |
    | Klikregistratie staat per campagne in `track_clicks`, standaard uit.
    |
    */

    'tracking' => [
        'opens' => false,
        'clicks_default' => false,
        'content' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplicatie
    |--------------------------------------------------------------------------
    |
    | Standaard krijgt elk lid zijn eigen gepersonaliseerde bericht, ook binnen
    | een gezin dat één adres deelt. Per mailing kan dat op `per_email` gezet
    | worden, wat de berichten samenvoegt.
    |
    */

    'deduplication' => [
        'default_strategy' => 'per_member',
        'spread_shared_addresses' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bewaartermijnen (dagen)
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'webhook_deliveries' => 30,
        'events' => 396,
        'quota_ledger' => 730,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uitschrijven
    |--------------------------------------------------------------------------
    |
    | De uitschrijflink heeft bewust geen vervaldatum en werkt zonder inloggen:
    | de procedure moet werkelijk eenvoudig zijn, ook vanuit een oude mail.
    | list_unsubscribe is op het Hobby plan niet beschikbaar, dus de link zit
    | in de body.
    |
    */

    'unsubscribe' => [
        'route' => 'rv-mail.unsubscribe',
        'fallback_route' => 'rv-mail.unsubscribe.request',
    ],

];
