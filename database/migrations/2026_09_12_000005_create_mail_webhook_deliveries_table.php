<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_webhook_deliveries', function (Blueprint $table): void {
            $table->id();

            // Unieke index maakt herhaalde levering onschadelijk.
            $table->string('ms_event_id')->unique();

            $table->string('type', 64);
            $table->boolean('signature_valid')->default(false);
            $table->json('raw_payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_webhook_deliveries');
    }
};
