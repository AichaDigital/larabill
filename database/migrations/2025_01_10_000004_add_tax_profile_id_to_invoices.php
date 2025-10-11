<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds tax_profile_id to invoices table to maintain a snapshot
     * of the user's tax profile at the time of invoice creation.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Add tax_profile_id foreign key (nullable for backwards compatibility)
            $table->unsignedBigInteger('tax_profile_id')->nullable()->after('user_id');
            $table->foreign('tax_profile_id')
                  ->references('id')
                  ->on('user_tax_profiles')
                  ->nullOnDelete();

            // Add index for efficient queries
            $table->index(['user_id', 'tax_profile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['tax_profile_id']);
            $table->dropIndex(['user_id', 'tax_profile_id']);
            $table->dropColumn('tax_profile_id');
        });
    }
};

