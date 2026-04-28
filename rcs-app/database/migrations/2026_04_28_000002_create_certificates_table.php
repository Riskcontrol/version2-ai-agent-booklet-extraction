<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            // Manual fields (copied from document once per upload)
            $table->string('date_received')->nullable();
            $table->string('completed_date')->nullable();
            $table->string('client_name')->nullable();
            // Extracted fields
            $table->string('name')->nullable();
            $table->string('institution')->nullable();
            $table->string('course')->nullable();
            $table->string('qualification')->nullable();
            $table->string('grade')->nullable();
            $table->string('session')->nullable();
            $table->string('matric_number')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
