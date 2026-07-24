<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('library_recipes', function (Blueprint $table) {
            $table->integer('calories')->nullable()->after('rating');
            $table->integer('servings')->nullable()->after('time_to_make');
            $table->json('steps')->nullable()->after('description');
            $table->json('ingredients_structured')->nullable()->after('ingredients');
        });

        // Migrate the free-text ingredients into a structured list. Legacy values
        // were newline separated (Chefkoch import) or comma separated (manual entry).
        DB::table('library_recipes')
            ->whereNotNull('ingredients')
            ->orderBy('id')
            ->chunkById(200, function ($recipes) {
                foreach ($recipes as $recipe) {
                    DB::table('library_recipes')
                        ->where('id', $recipe->id)
                        ->update(['ingredients_structured' => json_encode($this->parseIngredients($recipe->ingredients))]);
                }
            });

        Schema::table('library_recipes', function (Blueprint $table) {
            $table->dropColumn('ingredients');
        });

        Schema::table('library_recipes', function (Blueprint $table) {
            $table->renameColumn('ingredients_structured', 'ingredients');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('library_recipes', function (Blueprint $table) {
            $table->renameColumn('ingredients', 'ingredients_structured');
        });

        Schema::table('library_recipes', function (Blueprint $table) {
            $table->string('ingredients')->nullable()->after('description');
        });

        DB::table('library_recipes')
            ->whereNotNull('ingredients_structured')
            ->orderBy('id')
            ->chunkById(200, function ($recipes) {
                foreach ($recipes as $recipe) {
                    $items = json_decode($recipe->ingredients_structured, true) ?: [];
                    $lines = collect($items)
                        ->map(fn ($item) => trim(trim(($item['amount'] ?? '').' '.($item['unit'] ?? '')).' '.($item['name'] ?? '')))
                        ->filter()
                        ->implode("\n");

                    DB::table('library_recipes')
                        ->where('id', $recipe->id)
                        ->update(['ingredients' => $lines ?: null]);
                }
            });

        Schema::table('library_recipes', function (Blueprint $table) {
            $table->dropColumn(['ingredients_structured', 'steps', 'servings', 'calories']);
        });
    }

    /**
     * Split a legacy free-text ingredient string into structured items.
     */
    private function parseIngredients(string $ingredients): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($ingredients)) ?: [];

        if (count($lines) === 1) {
            $lines = explode(',', $lines[0]);
        }

        return collect($lines)
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(fn ($name) => ['name' => $name, 'amount' => null, 'unit' => null])
            ->values()
            ->all();
    }
};
