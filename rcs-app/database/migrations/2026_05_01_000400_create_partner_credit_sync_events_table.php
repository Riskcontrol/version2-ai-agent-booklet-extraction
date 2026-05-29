<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_credit_sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->nullable();
            $table->string('user_email')->nullable();
            $table->unsignedBigInteger('credit_balance')->nullable();
            $table->unsignedBigInteger('credit_cap')->nullable();
            $table->string('reported_status', 40)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->boolean('auth_valid')->default(false);
            $table->string('processing_status', 40)->default('received');
            $table->text('error_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index('user_email');
            $table->index('auth_valid');
            $table->index('processing_status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_credit_sync_events');
    }
};
