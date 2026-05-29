<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_request_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('partner_name')->index();
            $table->string('request_method', 10);
            $table->string('request_path');
            $table->longText('request_body')->nullable();
            $table->integer('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('signature_algorithm')->default('hmac-sha256');
            $table->string('request_timestamp');
            $table->string('request_nonce');
            $table->timestamps();
            $table->index(['partner_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_request_idempotency');
    }
};
