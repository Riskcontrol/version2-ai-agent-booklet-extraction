<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_authorization_rejections', function (Blueprint $table) {
            $table->id();
            $table->string('user_email')->nullable()->index();
            $table->uuid('partner_request_id')->nullable()->index();
            $table->string('partner_name')->nullable();
            $table->string('partner_domain')->nullable();
            $table->string('extraction_type', 50)->nullable()->index();
            $table->unsignedInteger('pages_requested')->default(0);
            $table->string('returned_api_tier')->nullable()->index();
            $table->string('reason');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_authorization_rejections');
    }
};