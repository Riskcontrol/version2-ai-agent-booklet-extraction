<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('user_email')->nullable()->after('filename');
            $table->uuid('partner_request_id')->nullable()->after('user_email');
            $table->string('api_key_tier')->nullable()->after('partner_request_id');
            $table->unsignedInteger('page_start')->nullable()->after('api_key_tier');
            $table->unsignedInteger('page_end')->nullable()->after('page_start');
            $table->unsignedInteger('pages_requested')->nullable()->after('page_end');
            $table->unsignedInteger('pages_processed')->nullable()->after('pages_requested');
            $table->unsignedInteger('pages_with_results')->nullable()->after('pages_processed');
            $table->unsignedBigInteger('credits_reserved')->default(0)->after('pages_with_results');
            $table->unsignedBigInteger('credits_consumed')->default(0)->after('credits_reserved');
            $table->unsignedBigInteger('credits_refunded')->default(0)->after('credits_consumed');
            $table->string('credit_status')->default('none')->after('credits_refunded');
            $table->text('failed_reason')->nullable()->after('credit_status');

            $table->unique('partner_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['partner_request_id']);
            $table->dropColumn([
                'user_email',
                'partner_request_id',
                'api_key_tier',
                'page_start',
                'page_end',
                'pages_requested',
                'pages_processed',
                'pages_with_results',
                'credits_reserved',
                'credits_consumed',
                'credits_refunded',
                'credit_status',
                'failed_reason',
            ]);
        });
    }
};