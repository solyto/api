<?php

namespace App\Mcp\Tools;

use App\Api\DevRequests\Resources\DevRequestResource;
use App\Api\DevRequests\Services\DevRequestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a feature request or bug report on the solyto dev request board. It is visible to all solyto users, so never include the user\'s private data (notes, todos, events, contacts) unless the user explicitly asked for it.')]
class CreateDevRequest extends SolytoTool
{
    // The REST endpoint's "dev-requests" limiter (AppServiceProvider), sharing its bucket: the key is
    // built the way Laravel's ThrottleRequests builds it for named limiters.
    private const string LIMITER = 'dev-requests';
    private const int MAX_PER_HOUR = 10;

    public function __construct(private readonly DevRequestService $devRequests) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);

        // Same rules as App\Api\DevRequests\Requests\StoreDevRequestRequest, without the screenshot.
        $validated = $request->validate([
            'type' => 'required|string|in:feature,bug',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:65535',
            'priority' => 'nullable|integer|min:1|max:5',
            'url' => 'nullable|string|max:255|url',
        ]);

        $key = md5(self::LIMITER . $user->id);

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            return Response::error('Too many dev requests, try again later.');
        }

        RateLimiter::hit($key, 3600);

        $devRequest = $this->devRequests->create($user, $validated);

        return Response::json(new DevRequestResource($devRequest)->resolve(request()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['feature', 'bug'])->required(),
            'title' => $schema->string()->max(255)->required(),
            'description' => $schema->string()->max(65535)->required(),
            'priority' => $schema->integer()->min(1)->max(5)->nullable()->description('How important this is to the user, 1 (low) to 5 (high).'),
            'url' => $schema->string()->max(255)->nullable()->description('URL of the page the request is about.'),
        ];
    }
}
