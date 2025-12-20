<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Converts name and description columns to JSON for spatie/laravel-translatable support.
     * Existing data is preserved in both 'es' and 'en' locales.
     */
    public function up(): void
    {
        // Step 1: Add temporary JSON columns
        Schema::table('articles', function (Blueprint $table) {
            $table->json('name_json')->nullable()->after('name');
            $table->json('description_json')->nullable()->after('description');
        });

        // Step 2: Migrate existing data to JSON format
        DB::table('articles')->orderBy('id')->chunk(100, function ($articles) {
            foreach ($articles as $article) {
                $nameJson = json_encode([
                    'es' => $article->name,
                    'en' => $article->name,
                ]);

                $descriptionJson = $article->description
                    ? json_encode([
                        'es' => $article->description,
                        'en' => $article->description,
                    ])
                    : null;

                DB::table('articles')
                    ->where('id', $article->id)
                    ->update([
                        'name_json'        => $nameJson,
                        'description_json' => $descriptionJson,
                    ]);
            }
        });

        // Step 3: Drop old columns and rename new ones
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->renameColumn('name_json', 'name');
            $table->renameColumn('description_json', 'description');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Converts JSON back to simple string/text columns (using 'es' locale as default).
     */
    public function down(): void
    {
        // Step 1: Add temporary string columns
        Schema::table('articles', function (Blueprint $table) {
            $table->string('name_str', 255)->nullable()->after('name');
            $table->text('description_str')->nullable()->after('description');
        });

        // Step 2: Extract 'es' locale data back to string columns
        DB::table('articles')->orderBy('id')->chunk(100, function ($articles) {
            foreach ($articles as $article) {
                $nameData = json_decode($article->name, true);
                $descData = $article->description ? json_decode($article->description, true) : null;

                DB::table('articles')
                    ->where('id', $article->id)
                    ->update([
                        'name_str'        => $nameData['es'] ?? $nameData['en'] ?? '',
                        'description_str' => $descData['es'] ?? $descData['en'] ?? null,
                    ]);
            }
        });

        // Step 3: Drop JSON columns and rename string columns
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->renameColumn('name_str', 'name');
            $table->renameColumn('description_str', 'description');
        });
    }
};
