<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('user_email')->index();
            $t->string('filename');
            $t->string('archive_path');
            $t->string('status')->default('uploading')->index();
            $t->unsignedBigInteger('size');
            $t->unsignedBigInteger('bytes_received')->default(0);
            $t->json('inventory')->nullable();
            $t->json('notices')->nullable();
            $t->unsignedInteger('pages_requested')->default(0);
            $t->unsignedInteger('pages_processed')->default(0);
            $t->unsignedInteger('pages_with_results')->default(0);
            $t->unsignedInteger('files_processed')->default(0);
            $t->unsignedInteger('revision')->default(0);
            $t->string('worker_run')->nullable();
            $t->uuid('partner_request_id')->nullable();
            $t->string('api_key_tier')->nullable();
            $t->string('credit_status')->default('none');
            $t->unsignedInteger('credits_reserved')->default(0);
            $t->unsignedInteger('credits_consumed')->default(0);
            $t->unsignedInteger('credits_refunded')->default(0);
            $t->text('failed_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('verification_records', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('batch_id')->constrained('verification_batches')->cascadeOnDelete();
            $t->string('record_key', 100);
            $t->string('role', 20)->index();
            $t->string('source_id', 64);
            $t->text('filename');
            $t->unsignedInteger('page');
            $t->json('fields');
            $t->json('evidence')->nullable();
            $t->timestamps();
            $t->unique(['batch_id', 'record_key']);
        });
        Schema::create('verification_results', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('batch_id')->constrained('verification_batches')->cascadeOnDelete();
            $t->foreignId('certificate_id')->unique()->constrained('verification_records')->cascadeOnDelete();
            $t->foreignId('report_id')->nullable()->unique()->constrained('verification_records')->nullOnDelete();
            $t->string('status', 30)->index();
            $t->text('reason');
            $t->json('comparisons');
            $t->json('candidates');
            $t->boolean('manual')->default(false);
            $t->timestamps();
        });
        Schema::create('verification_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('batch_id')->constrained('verification_batches')->cascadeOnDelete();
            $t->string('event_key', 100);
            $t->json('details');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['batch_id', 'event_key']);
        });
        Schema::create('verification_pairing_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('batch_id')->constrained('verification_batches')->cascadeOnDelete();
            $t->foreignId('certificate_id')->constrained('verification_records')->cascadeOnDelete();
            $t->unsignedBigInteger('previous_report_id')->nullable();
            $t->unsignedBigInteger('report_id')->nullable();
            $t->string('user_email');
            $t->text('note');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['verification_pairing_audits', 'verification_events', 'verification_results', 'verification_records', 'verification_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
