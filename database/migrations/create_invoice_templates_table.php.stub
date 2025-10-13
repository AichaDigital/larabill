<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Template name (e.g., 'default', 'modern', 'minimal')
            $table->string('display_name'); // Human readable name
            $table->string('type'); // invoice_type: fiscal, proforma, reverse-charge, exempt
            $table->string('template_path'); // Blade template path
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // Template-specific settings
            $table->timestamps();

            // Indexes
            $table->index(['type', 'is_active']);
            $table->index(['type', 'is_default']);
            $table->unique(['name', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_templates');
    }
};

