<?php

declare(strict_types=1);

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
        if (! Schema::hasTable('invoice_items') || Schema::hasColumn('invoice_items', 'article_id')) {
            return;
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('article_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('articles')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('invoice_items') || ! Schema::hasColumn('invoice_items', 'article_id')) {
            return;
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('article_id');
        });
    }
};
