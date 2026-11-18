<?php

namespace App\Mcp\Tools;

use App\Api\Todos\Resources\TodoResource;
use App\Api\Todos\Services\TodoService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Unlike the REST endpoint, this skips TodoService::parse(): it would create tags and categories from
 * "#tag" and "/category" in the title. Here they must already exist and are passed by id.
 */
#[Description('Create a todo for the user. Category and tag ids come from list-todo-tags-and-categories; new tags or categories cannot be created.')]
class CreateTodo extends SolytoTool
{
    public function __construct(private readonly TodoService $todos) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);

        // Same rules as App\Api\Todos\Requests\StoreTodoRequest, but with strict dates.
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'link' => 'nullable|string|max:2048',
            'priority' => 'sometimes|in:low,medium,high',
            'due_at' => 'nullable|date_format:Y-m-d|required_with:recurrence_frequency',
            'status' => 'sometimes|in:backlog,pending,in-progress,waiting,almost-done',
            'progress' => 'sometimes|nullable|integer|min:0|max:100',
            'effort' => 'sometimes|nullable|in:low,medium,high',
            'category_id' => ['nullable', Rule::exists('todo_categories', 'id')->where('user_id', $user->id)],
            'tags' => 'nullable|array',
            'tags.*' => [Rule::exists('tags', 'id')->where('user_id', $user->id)],
            'recurrence_frequency' => 'nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'sometimes|integer|min:1|max:365',
            'recurrence_ends_at' => 'nullable|date_format:Y-m-d',
        ]);

        $todo = $this->todos->create($user, $validated);

        return Response::json(new TodoResource($todo)->resolve(request()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->required(),
            'description' => $schema->string()->max(1000)->nullable(),
            'link' => $schema->string()->max(2048)->nullable(),
            'priority' => $schema->string()->enum(['low', 'medium', 'high'])->description('Defaults to medium.'),
            'due_at' => $schema->string()->nullable()->description('Due date as YYYY-MM-DD. Required for recurring todos.'),
            'status' => $schema->string()->enum(['backlog', 'pending', 'in-progress', 'waiting', 'almost-done']),
            'progress' => $schema->integer()->min(0)->max(100)->nullable()->description('Progress in percent.'),
            'effort' => $schema->string()->enum(['low', 'medium', 'high'])->nullable(),
            'category_id' => $schema->integer()->nullable()->description('Id of one of the user\'s todo categories.'),
            'tags' => $schema->array()->items($schema->integer())->nullable()->description('Ids of the user\'s tags.'),
            'recurrence_frequency' => $schema->string()->enum(['daily', 'weekly', 'monthly', 'yearly'])->nullable(),
            'recurrence_interval' => $schema->integer()->min(1)->max(365)->description('Repeat every N days/weeks/months/years.'),
            'recurrence_ends_at' => $schema->string()->nullable()->description('Date (YYYY-MM-DD) after which the todo stops recurring.'),
        ];
    }
}
