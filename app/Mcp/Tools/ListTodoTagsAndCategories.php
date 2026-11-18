<?php

namespace App\Mcp\Tools;

use App\Api\Tags\Resources\TagResource;
use App\Api\Tags\Services\TagService;
use App\Api\Todos\Resources\TodoCategoryResource;
use App\Api\Todos\Services\TodoCategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List all of the user\'s tags and todo categories with their ids, for use with create-todo and update-todo.')]
class ListTodoTagsAndCategories extends SolytoTool
{
    public function __construct(
        private readonly TagService $tags,
        private readonly TodoCategoryService $categories,
    ) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);

        return Response::json([
            'tags' => TagResource::collection($this->tags->list($user))->resolve(request()),
            'categories' => TodoCategoryResource::collection($this->categories->list($user))->resolve(request()),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
