<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_trusted_keys', function (Blueprint $table) {
            $table->id();
            $table->string('partner_name')->unique();
            $table->string('partner_domain');
            $table->string('current_secret_key');
            $table->string('current_key_id');
            $table->timestamp('secret_rotated_at');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index('partner_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_trusted_keys');
    }
};
