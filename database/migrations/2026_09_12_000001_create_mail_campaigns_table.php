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

    public function up(): void
    {
        Schema::create('mail_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('name');
            $table->string('category', 32);

            // Onderwerp en body blijven als sjabloon staan: personalisatie wordt
            // per bestemmeling toegepast, niet hier ingevuld.
            $table->string('subject_template', 512);
            $table->longText('body_markdown');

            $table->string('from_email');
            $table->string('from_name');
            $table->string('reply_to')->nullable();

            $table->string('audience_type', 64);
            $table->json('audience_params')->nullable();
            $table->string('dedup_strategy', 32)->default('per_member');

            $table->string('status', 32)->default('draft');

            // Openregistratie staat vast uit en is daarom geen kolom.
            $table->boolean('track_clicks')->default(false);

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('composed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('created_by')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Denormaliseerde tellers, incrementeel bijgehouden door de
            // webhookverwerking. Geen 'opened': dat wordt niet gemeten.
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('recipients_sendable')->default(0);
            $table->unsignedInteger('count_sent')->default(0);
            $table->unsignedInteger('count_delivered')->default(0);
            $table->unsignedInteger('count_clicked')->default(0);
            $table->unsignedInteger('count_soft_bounced')->default(0);
            $table->unsignedInteger('count_hard_bounced')->default(0);
            $table->unsignedInteger('count_complained')->default(0);
            $table->unsignedInteger('count_suppressed')->default(0);
            $table->unsignedInteger('count_failed')->default(0);

            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaigns');
    }
};
