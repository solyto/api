<?php

namespace App\Mcp\Tools;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Resources\DevRequestCommentResource;
use App\Api\DevRequests\Services\DevRequestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get one dev request by its id (from list-dev-requests), including all of its comments, without who wrote them.')]
class GetDevRequest extends SolytoTool
{
    private const array OMITTED_COMMENT_FIELDS = ['user'];

    public function __construct(private readonly DevRequestService $devRequests) {}

    public function handle(Request $request): Response
    {
        $id = $request->validate(['id' => ['required', 'integer']])['id'];

        $devRequest = DevRequest::with('votes')->withCount('comments')->find($id);

        if (!$devRequest) {
            return Response::error('Dev request not found.');
        }

        $comments = DevRequestCommentResource::collection($this->devRequests->listComments($devRequest))->resolve(request());

        return Response::json([
            ...ListDevRequests::present($devRequest),
            'comments' => collect($comments)
                ->map(fn (array $comment) => Arr::except($comment, self::OMITTED_COMMENT_FIELDS))
                ->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The dev request id.')->required(),
        ];
    }
}
