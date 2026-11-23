<?php

namespace App\Mcp\Tools;

use App\Api\Todos\Models\Todo;
use App\Api\Todos\Requests\UpdateTodoRequest;
use App\Api\Todos\Resources\TodoResource;
use App\Api\Todos\Services\TodoService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update one of the user\'s todos by its id (from list-todos). Only the fields you pass are changed; pass null to clear a nullable field. Category and tag ids come from list-todo-tags-and-categories.')]
class UpdateTodo extends SolytoTool
{
    public function __construct(private readonly TodoService $todos) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'id' => ['required', 'uuid'],
            ...UpdateTodoRequest::sharedRules($user->id),
        ]);

        $todo = Todo::forUser($user->id)->whereKey($validated['id'])->first();

        if (!$todo) {
            return Response::error('Todo not found.');
        }

        $todo = $this->todos->update($todo, Arr::except($validated, 'id'));

        return Response::json(new TodoResource($todo)->resolve(request()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The todo id.')->required(),
            'title' => $schema->string()->max(255),
            'description' => $schema->string()->max(1000)->nullable(),
            'link' => $schema->string()->max(2048)->nullable(),
            'priority' => $schema->string()->enum(['low', 'medium', 'high']),
            'due_at' => $schema->string()->nullable()->description('Due date as YYYY-MM-DD, DD.MM.YYYY, "today" or "tomorrow".'),
            'status' => $schema->string()->enum(['backlog', 'pending', 'in-progress', 'waiting', 'almost-done']),
            'progress' => $schema->integer()->min(0)->max(100)->nullable()->description('Progress in percent.'),
            'effort' => $schema->string()->enum(['low', 'medium', 'high'])->nullable(),
            'is_completed' => $schema->boolean()->description('Completing a recurring todo creates its next occurrence.'),
            'category_id' => $schema->integer()->nullable()->description('Id of one of the user\'s todo categories.'),
            'tags' => $schema->array()->items($schema->integer())->nullable()->description('Ids of the user\'s tags. Replaces all of the todo\'s tags.'),
            'recurrence_frequency' => $schema->string()->enum(['daily', 'weekly', 'monthly', 'yearly'])->nullable(),
            'recurrence_interval' => $schema->integer()->min(1)->max(365)->description('Repeat every N days/weeks/months/years.'),
            'recurrence_ends_at' => $schema->string()->nullable()->description('Date after which the todo stops recurring.'),
        ];
    }
}
