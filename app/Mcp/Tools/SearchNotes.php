<?php

namespace App\Mcp\Tools;

use App\Api\Notes\Services\NoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Search the user\'s notes by title and content. Returns ids, titles and a short excerpt; use get-note for the full text. Notes in hidden categories are never included.')]
class SearchNotes extends SolytoTool
{
    private const int MAX_RESULTS = 20;
    private const int EXCERPT_LENGTH = 200;

    public function __construct(private readonly NoteService $notes) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['query' => ['required', 'string', 'min:2', 'max:200']]);
        $query = mb_strtolower($validated['query']);

        $matches = collect($this->notes->list($this->user($request)))
            ->filter(fn (array $note) => str_contains(mb_strtolower($note['title'] . ' ' . $note['content']), $query))
            ->take(self::MAX_RESULTS)
            ->map(fn (array $note) => [
                'id' => $note['id'],
                'title' => $note['title'],
                'excerpt' => Str::limit(strip_tags((string) $note['content']), self::EXCERPT_LENGTH),
                'updated_at' => $note['updated_at'],
            ])
            ->values();

        return Response::json($matches);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Text to look for in note titles and content.')->required(),
        ];
    }
}
