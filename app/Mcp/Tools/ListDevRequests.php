<?php

namespace App\Mcp\Tools;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Resources\DevRequestResource;
use App\Api\DevRequests\Services\DevRequestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List solyto dev requests (feature requests and bug reports submitted by users, without who submitted them), highest voted first. Use get-dev-request for the comments on one.')]
class ListDevRequests extends SolytoTool
{
    private const array STATUSES = ['backlog', 'pending', 'in-progress', 'waiting-for-deployment', 'waiting-for-user', 'completed', 'cancelled'];
    private const array OMITTED_FIELDS = ['user_vote', 'created_by_user_id'];
    private const array TYPES = ['feature', 'bug'];
    private const int DEFAULT_LIMIT = 25;
    private const int MAX_LIMIT = 100;

    public function __construct(private readonly DevRequestService $devRequests) {}

    public function handle(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:' . implode(',', self::STATUSES)],
            'type' => ['nullable', 'string', 'in:' . implode(',', self::TYPES)],
            'query' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);
        $query = isset($filters['query']) ? mb_strtolower($filters['query']) : null;

        $devRequests = $this->devRequests->list()
            ->when($filters['status'] ?? null, fn ($list, $status) => $list->where('status', $status))
            ->when($filters['type'] ?? null, fn ($list, $type) => $list->where('type', $type))
            ->when($query, fn ($list) => $list->filter(
                fn (DevRequest $devRequest) => str_contains(mb_strtolower($devRequest->title . ' ' . $devRequest->description), $query)
            ))
            ->take($filters['limit'] ?? self::DEFAULT_LIMIT)
            ->map(fn (DevRequest $devRequest) => self::present($devRequest))
            ->values();

        return Response::json($devRequests);
    }

    /**
     * The resource downgrades `waiting-for-deployment` and `waiting-for-user` for clients that do not send a recent version header, which MCP clients never do.
     *
     * @return array<string, mixed>
     */
    public static function present(DevRequest $devRequest): array
    {
        return [
            ...Arr::except(new DevRequestResource($devRequest)->resolve(request()), self::OMITTED_FIELDS),
            'status' => $devRequest->status,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(self::STATUSES)->description('Only return dev requests with this status.'),
            'type' => $schema->string()->enum(self::TYPES)->description('Only return feature requests or bug reports.'),
            'query' => $schema->string()->description('Only return dev requests whose title or description contains this text.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(self::DEFAULT_LIMIT)->description('Maximum number of dev requests to return.'),
        ];
    }
}
