<?php

use App\Api\Users\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;

function trafficLogRecords(): array
{
    return Log::channel('traffic')->getLogger()->getHandlers()[0]->getRecords();
}

function hitApi($test, User $user, int $times): void
{
    Sanctum::actingAs($user);

    for ($i = 0; $i < $times; $i++) {
        $test->getJson('/api/v1/users/me')->assertOk();
    }
}

describe('API traffic alert', function () {
    beforeEach(function () {
        config([
            'services.ntfy.url' => 'https://ntfy.example.test',
            'services.ntfy.topic' => 'solyto-admin',
            'services.traffic.alert_threshold' => 3,
            'services.traffic.alert_cooldown' => 3600,
            'logging.channels.traffic' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
        Http::fake();
    });

    it('sends nothing and logs nothing below the threshold', function () {
        hitApi($this, User::factory()->create(), 3);

        Http::assertNothingSent();
        expect(trafficLogRecords())->toBeEmpty();
    });

    it('sends exactly one alert when the threshold is crossed', function () {
        $user = User::factory()->create();

        hitApi($this, $user, 4);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://ntfy.example.test'
            && $request['topic'] === 'solyto-admin'
            && $request['title'] === 'High API traffic'
            && str_contains($request['message'], "User {$user->id} ")
            && !str_contains($request->body(), $user->email)
            && !str_contains($request->body(), $user->name)
        );

        $records = trafficLogRecords();
        expect($records)->toHaveCount(1)
            ->and($records[0]->context['user_id'])->toBe($user->id)
            ->and($records[0]->context['count'])->toBe(4)
            ->and($records[0]->context['route'])->toBe('api/v1/users/me');
    });

    it('sends no second alert while above the threshold within the cooldown', function () {
        hitApi($this, User::factory()->create(), 10);

        Http::assertSentCount(1);
        expect(trafficLogRecords())->toHaveCount(1);
    });

    it('counts and alerts two users separately', function () {
        $first = User::factory()->create();
        $second = User::factory()->create();

        hitApi($this, $first, 3);
        hitApi($this, $second, 3);

        Http::assertNothingSent();

        hitApi($this, $first, 1);
        hitApi($this, $second, 1);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request['message'], "User {$first->id} "));
        Http::assertSent(fn (Request $request) => str_contains($request['message'], "User {$second->id} "));
    });

    it('sends nothing when ntfy is not configured and the request still succeeds', function () {
        config(['services.ntfy.url' => null, 'services.ntfy.topic' => null]);

        hitApi($this, User::factory()->create(), 4);

        Http::assertNothingSent();
        expect(trafficLogRecords())->toHaveCount(1);
    });

    it('does not count unauthenticated routes', function () {
        RateLimiter::spy();

        $this->getJson('/api/v1/health')->assertOk();

        RateLimiter::shouldNotHaveReceived('hit');
    });
});
