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
     * Een lijst die niemand meer gebruikt is een lijst die niemand meer
     * onderhoudt. Door het laatste gebruik bij te houden kan het overzicht dat
     * tonen, zonder dat er iets automatisch verdwijnt.
     */
    public function up(): void
    {
        Schema::table('mail_distribution_lists', function (Blueprint $table): void {
            $table->timestamp('last_used_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('mail_distribution_lists', function (Blueprint $table): void {
            $table->dropColumn('last_used_at');
        });
    }
};
