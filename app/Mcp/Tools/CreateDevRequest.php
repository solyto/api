<?php

namespace App\Mcp\Tools;

use App\Api\DevRequests\Requests\StoreDevRequestRequest;
use App\Api\DevRequests\Resources\DevRequestResource;
use App\Api\DevRequests\Services\DevRequestService;
use App\Api\Users\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a feature request or bug report on the solyto dev request board. It is visible to all solyto users, so never include the user\'s private data (notes, todos, events, contacts) unless the user explicitly asked for it.')]
class CreateDevRequest extends SolytoTool
{
    // The REST endpoint's named limiter (AppServiceProvider), so both entry points share one hourly bucket.
    private const string LIMITER = 'dev-requests';
    private const array SCREENSHOT_FIELDS = ['screenshot', 'screenshot_name'];

    public function __construct(private readonly DevRequestService $devRequests) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);

        $validated = $request->validate(Arr::except(StoreDevRequestRequest::sharedRules(), self::SCREENSHOT_FIELDS));

        $limit = $this->limitFor($user);
        // The key Laravel's ThrottleRequests builds for a named limiter.
        $key = md5(self::LIMITER . $limit->key);

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            return Response::error('Too many dev requests, try again later.');
        }

        RateLimiter::hit($key, $limit->decaySeconds);

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

    // The named limiter reads the user from an HTTP request; this one carries only that user.
    private function limitFor(User $user): Limit
    {
        return RateLimiter::limiter(self::LIMITER)((new HttpRequest())->setUserResolver(fn () => $user));
    }
}
