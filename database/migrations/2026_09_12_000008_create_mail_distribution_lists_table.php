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
        Schema::create('mail_distribution_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('owner_id')->nullable();
            $table->timestamps();
        });

        Schema::create('mail_distribution_list_members', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('distribution_list_id')
                ->constrained('mail_distribution_lists')
                ->cascadeOnDelete();

            // Ofwel een clublid, ofwel een externe bestemmeling. Externen lopen
            // uitsluitend via deze lijsten, die een expliciete beheerder hebben.
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('name')->nullable();

            $table->timestamps();

            $table->unique(['distribution_list_id', 'member_id'], 'mail_dl_member_unique');
            $table->unique(['distribution_list_id', 'email'], 'mail_dl_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_distribution_list_members');
        Schema::dropIfExists('mail_distribution_lists');
    }
};
