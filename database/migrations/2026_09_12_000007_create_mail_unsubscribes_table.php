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
     * Granulair per categorie: iemand kan het clubnieuws afzetten zonder de
     * permanentie-oproepen te missen. Enkel categorieën die op gerechtvaardigd
     * belang steunen zijn uitschrijfbaar.
     */
    public function up(): void
    {
        Schema::create('mail_unsubscribes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('email');
            $table->string('category', 32);
            $table->string('source', 64)->nullable();
            $table->timestamp('unsubscribed_at');
            $table->timestamps();

            $table->unique(['email', 'category']);
            $table->index(['member_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_unsubscribes');
    }
};
