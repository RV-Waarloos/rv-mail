<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('campaign_id')
                ->constrained('mail_campaigns')
                ->cascadeOnDelete();

            $table->foreignId('batch_id')
                ->nullable()
                ->constrained('mail_campaign_batches')
                ->nullOnDelete();

            // Geen foreign key naar de ledentabel: die hoort bij rv-core en het
            // snapshot moet blijven staan als een lid verdwijnt of anonimiseert.
            $table->unsignedBigInteger('member_id')->nullable()->index();

            $table->string('email');
            $table->string('name')->nullable();
            $table->json('personalization')->nullable();

            $table->string('status', 32)->default('pending');
            $table->string('skip_reason', 64)->nullable();

            $table->string('ms_message_id')->nullable()->index();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('first_clicked_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            // Maakt hersamenstellen idempotent: dezelfde persoon kan niet twee
            // keer in dezelfde campagne belanden.
            $table->unique(['campaign_id', 'email', 'member_id'], 'mail_recipients_campaign_unique');
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaign_recipients');
    }
};
