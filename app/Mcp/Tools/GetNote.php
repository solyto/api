<?php

namespace App\Mcp\Tools;

use App\Api\Notes\Models\Note;
use App\Api\Notes\Resources\NoteResource;
use App\Api\Notes\Services\NoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get the full content of one of the user\'s notes by its id (from search-notes).')]
class GetNote extends SolytoTool
{
    public function __construct(private readonly NoteService $notes) {}

    public function handle(Request $request): Response
    {
        $id = $request->validate(['id' => ['required', 'uuid']])['id'];
        $user = $this->user($request);

        $note = Note::forUser($user->id)
            ->whereKey($id)
            ->where(fn ($query) => $query->whereNull('category_id')
                ->orWhereNotIn('category_id', $this->notes->hiddenCategoryIds($user->id)))
            ->first();

        if (!$note) {
            return Response::error('Note not found.');
        }

        return Response::json(new NoteResource($note)->resolve(request()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The note id.')->required(),
        ];
    }
}
