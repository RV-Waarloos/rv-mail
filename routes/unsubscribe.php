<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RvWaarloos\RvMail\Http\Controllers\UnsubscribeController;

/*
 * Bewust buiten de web-middleware: geen sessie, geen login, geen CSRF-token.
 * De ondertekende URL is de authenticatie, en een uitschrijfpagina die eerst
 * een sessie nodig heeft is precies de omweg die we willen vermijden.
 *
 * De handtekening heeft geen vervaldatum: een lid moet ook vanuit een mail van
 * twee jaar oud in een klik kunnen uitschrijven.
 */

$path = (string) config('rv-mail.unsubscribe.path', 'uitschrijven');

Route::middleware('signed')->group(static function () use ($path): void {
    Route::get($path.'/{recipient}', [UnsubscribeController::class, 'show'])
        ->name('rv-mail.unsubscribe');

    Route::post($path.'/{recipient}', [UnsubscribeController::class, 'store'])
        ->name('rv-mail.unsubscribe.store');
});

// Vangnet wanneer de handtekening niet klopt, bijvoorbeeld na een hersleuteling
// of doordat een mailclient de URL heeft verminkt.
Route::get($path, [UnsubscribeController::class, 'request'])
    ->name('rv-mail.unsubscribe.request');

Route::post($path, [UnsubscribeController::class, 'sendLink'])
    ->middleware('throttle:6,1')
    ->name('rv-mail.unsubscribe.send-link');
