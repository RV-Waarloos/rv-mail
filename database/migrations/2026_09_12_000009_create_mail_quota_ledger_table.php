<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MailerSend telt per rollend venster van 30 dagen en biedt geen API om het
     * verbruik op te vragen. Dit is de eigen teller; het periodeverbruik is de
     * som over het venster.
     */
    public function up(): void
    {
        Schema::create('mail_quota_ledger', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('emails_sent')->default(0);
            $table->unsignedInteger('emails_transactional')->default(0);
            $table->unsignedInteger('api_requests')->default(0);
            $table->unsignedInteger('bulk_requests')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_quota_ledger');
    }
};
