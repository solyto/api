<?php

use App\Api\Notes\Models\Note;
use App\Api\Notes\Services\NoteService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $notes = app(NoteService::class);

        Note::query()->select(['id', 'user_id', 'content'])->chunkById(500, function ($chunk) use ($notes) {
            foreach ($chunk as $note) {
                $notes->syncLinks($note);
            }
        });
    }

    public function down(): void
    {
        // The rows go with the table in the previous migration's down().
    }
};
