<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'central';
    }

    /**
     * Append-only. Dit is de bron van waarheid voor opvolging: MailerSend bewaart
     * op het Hobby plan maar 24 uur activity-data.
     *
     * Let op de scheiding met de audittabel van owen-it/laravel-auditing: deze
     * tabel gaat over wat MailerSend met een bericht deed, de audittabel over wat
     * een mens met een campagne deed.
     */
    public function up(): void
    {
        Schema::create('mail_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('campaign_id')
                ->nullable()
                ->constrained('mail_campaigns')
                ->cascadeOnDelete();

            $table->foreignId('recipient_id')
                ->nullable()
                ->constrained('mail_campaign_recipients')
                ->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('ms_message_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->json('payload')->nullable();

            $table->index(['campaign_id', 'type']);
            $table->index(['recipient_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_events');
    }
};
