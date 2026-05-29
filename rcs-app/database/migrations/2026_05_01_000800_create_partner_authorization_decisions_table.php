<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_authorization_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_request_id')->nullable()->index();
            $table->string('user_email')->index();
            $table->string('extraction_type', 50)->index();
            $table->unsignedInteger('pages_requested');
            $table->string('decision', 20)->index(); // authorized|denied|bypassed|error
            $table->string('enforcement_mode', 20)->index(); // shadow|hard_block
            $table->boolean('hard_blocked')->default(false);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_email', 'created_at']);
            $table->index(['decision', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_authorization_decisions');
    }
};
