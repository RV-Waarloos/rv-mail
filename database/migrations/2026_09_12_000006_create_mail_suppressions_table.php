<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason', 32);
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->string('source', 64)->nullable();
            $table->timestamp('suppressed_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_suppressions');
    }
};
