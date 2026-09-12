<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_campaign_batches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('campaign_id')
                ->constrained('mail_campaigns')
                ->cascadeOnDelete();

            $table->unsignedInteger('sequence');
            $table->unsignedInteger('size');
            $table->unsignedBigInteger('payload_bytes')->default(0);

            $table->string('state', 32)->default('pending');

            // Wordt gezet vóór de HTTP-call. Gaat de call in timeout en probeert
            // de job opnieuw, dan is dit de sleutel om te reconciliëren in plaats
            // van blind opnieuw te versturen.
            $table->ulid('request_ulid')->nullable();
            $table->string('bulk_email_id')->nullable()->index();

            $table->json('validation_errors')->nullable();
            $table->json('suppressed_recipients')->nullable();

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            $table->unique(['campaign_id', 'sequence']);
            $table->index(['state', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaign_batches');
    }
};
