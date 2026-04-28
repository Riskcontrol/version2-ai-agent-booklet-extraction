<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->enum('extraction_type', ['convocation', 'certificates'])->default('convocation')->after('status');
            // Certificate manual fields stored on the document record
            $table->string('date_received')->nullable()->after('extraction_type');
            $table->string('completed_date')->nullable()->after('date_received');
            $table->string('client_name')->nullable()->after('completed_date');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['extraction_type', 'date_received', 'completed_date', 'client_name']);
        });
    }
};
