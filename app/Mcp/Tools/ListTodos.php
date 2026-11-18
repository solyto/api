<?php

namespace App\Mcp\Tools;

use App\Api\Todos\Models\Todo;
use App\Api\Todos\Resources\TodoResource;
use App\Api\Todos\Services\TodoService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s open todos, soonest due first. Optionally filter by text in the title or description.')]
class ListTodos extends SolytoTool
{
    public function __construct(private readonly TodoService $todos) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['query' => ['nullable', 'string', 'max:200']]);
        $query = $validated['query'] ?? null;

        $todos = $this->todos->list($this->user($request))
            ->reject(fn (Todo $todo) => $todo->is_completed)
            ->when($query, fn ($todos) => $todos->filter(
                fn (Todo $todo) => str_contains(mb_strtolower($todo->title . ' ' . $todo->description), mb_strtolower($query))
            ))
            ->sortBy(fn (Todo $todo) => $todo->due_at->timestamp ?? PHP_INT_MAX)
            ->values();

        return Response::json(TodoResource::collection($todos)->resolve(request()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Only return todos whose title or description contains this text.'),
        ];
    }
}
