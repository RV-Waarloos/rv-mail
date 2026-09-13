<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RvWaarloos\RvMail\Http\Controllers\MailerSendWebhookController;
use RvWaarloos\RvMail\Http\Middleware\VerifyMailerSendSignature;

/*
 * Buiten de web-middleware: geen sessie, geen CSRF, geen auth. MailerSend moet
 * hier ongehinderd bij kunnen.
 *
 * Registreer deze route op een app die publiek bereikbaar en warm is. Staat de
 * container op minReplicas 0, dan botst de eerste call van een piek op een cold
 * start.
 */
Route::post(config('rv-mail.webhook.path', 'webhooks/mailersend'), MailerSendWebhookController::class)
    ->middleware(VerifyMailerSendSignature::class)
    ->name('rv-mail.webhook');
