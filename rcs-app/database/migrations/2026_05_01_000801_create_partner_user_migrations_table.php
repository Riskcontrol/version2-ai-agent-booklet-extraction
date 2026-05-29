<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_user_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('riskcontrol_user_email')->unique();
            $table->string('partner_user_email');
            $table->unsignedBigInteger('partner_user_id')->nullable();
            $table->unsignedBigInteger('opening_balance')->default(0);
            $table->unsignedBigInteger('opening_cap')->default(0);
            $table->string('status', 20)->default('pending')->index(); // pending|migrated|failed
            $table->timestamp('migrated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_user_migrations');
    }
};
