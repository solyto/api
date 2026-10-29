<?php

use App\Api\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;

/**
 * A session token with an expiry, like login issues.
 */
function sessionToken(User $user, ?DateTimeInterface $expiresAt = null): string
{
    return $user->createToken('auth-token-mobile', expiresAt: $expiresAt ?? now()->addDays(30))->plainTextToken;
}

function tokenRecord(string $plainTextToken): PersonalAccessToken
{
    return PersonalAccessToken::findToken($plainTextToken);
}

/**
 * Each request must resolve its own bearer token; the guard caches the first user otherwise.
 */
function bearer($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', 'Bearer '.$token);
}

function issueWidgetToken($test, string $parentToken): string
{
    return bearer($test, $parentToken)->postJson('/api/v1/auth/widget-token')
        ->assertStatus(201)
        ->json('data.token');
}

function registerWidgetProbeRoute(): void
{
    Route::middleware('auth:sanctum')->get('/api/v1/widgets/probe', fn () => response()->json(['ok' => true]))->name('widgets.probe');
    Route::middleware('auth:sanctum')->get('/api/v1/unnamed-probe', fn () => response()->json(['ok' => true]));
    app('router')->getRoutes()->refreshNameLookups();
}

describe('Widget token scope gate', function () {
    it('rejects a widget token outside widgets.* routes', function () {
        $user = makeUser();
        $widgetToken = issueWidgetToken($this, sessionToken($user));

        expectApiError(bearer($this, $widgetToken)->getJson('/api/v1/users/me'), 401);
        expectApiError(bearer($this, $widgetToken)->postJson('/api/v1/auth/refresh'), 401);
        expectApiError(bearer($this, $widgetToken)->postJson('/api/v1/auth/widget-token'), 401);
        expectApiError(bearer($this, $widgetToken)->getJson('/api/v1/auth/tokens'), 401);
    });

    it('keeps a normal token working on the same routes', function () {
        $user = makeUser();
        $token = sessionToken($user);

        expectApiSuccess(bearer($this, $token)->getJson('/api/v1/users/me'));
        expectApiSuccess(bearer($this, $token)->getJson('/api/v1/auth/tokens'));
        expectApiSuccess(bearer($this, $token)->postJson('/api/v1/auth/widget-token'), 201);
        expectApiSuccess(bearer($this, $token)->postJson('/api/v1/auth/refresh'));
    });

    it('accepts a widget token on a widgets.* route and rejects it on an unnamed one', function () {
        registerWidgetProbeRoute();
        $user = makeUser();
        $widgetToken = issueWidgetToken($this, sessionToken($user));

        bearer($this, $widgetToken)->getJson('/api/v1/widgets/probe')->assertOk()->assertJsonPath('ok', true);
        bearer($this, $widgetToken)->getJson('/api/v1/unnamed-probe')->assertStatus(401);
    });

    it('sees the resolved route when the gate runs', function () {
        registerWidgetProbeRoute();
        $user = makeUser();
        $widgetToken = issueWidgetToken($this, sessionToken($user));

        $gate = Sanctum::$accessTokenAuthenticationCallback;
        $seenRouteName = null;
        Sanctum::authenticateAccessTokensUsing(function ($token, $isValid) use ($gate, &$seenRouteName) {
            $seenRouteName = request()->route()?->getName();

            return $gate($token, $isValid);
        });

        try {
            bearer($this, $widgetToken)->getJson('/api/v1/widgets/probe')->assertOk();
        } finally {
            Sanctum::authenticateAccessTokensUsing($gate);
        }

        expect($seenRouteName)->toBe('widgets.probe');
    });

    it('rejects the widget token once its parent has expired', function () {
        registerWidgetProbeRoute();
        $user = makeUser();
        $widgetToken = issueWidgetToken($this, sessionToken($user, now()->addHour()));

        $this->travel(2)->hours();

        bearer($this, $widgetToken)->getJson('/api/v1/widgets/probe')->assertStatus(401);
    });
});

describe('Issuing a widget token', function () {
    it('returns the token like login does', function () {
        $user = makeUser();
        $parentToken = sessionToken($user);

        $response = bearer($this, $parentToken)->postJson('/api/v1/auth/widget-token');

        expectApiSuccess($response, 201);
        $response->assertJsonStructure(['data' => ['token', 'token_type', 'token_expires_at']])
            ->assertJsonPath('data.token_type', 'Bearer');

        $widget = tokenRecord($response->json('data.token'));
        expect($widget->name)->toBe('widget-token')
            ->and($widget->abilities)->toBe(['widget'])
            ->and($widget->parent_token_id)->toBe(tokenRecord($parentToken)->id);
    });

    it('gives the widget token the parent expiry', function () {
        $user = makeUser();
        $parentToken = sessionToken($user, now()->addDays(7)->startOfSecond());

        $response = bearer($this, $parentToken)->postJson('/api/v1/auth/widget-token');

        $parent = tokenRecord($parentToken);
        expect(tokenRecord($response->json('data.token'))->expires_at->equalTo($parent->expires_at))->toBeTrue()
            ->and($response->json('data.token_expires_at'))->toBe($parent->expires_at->toIso8601String());
    });

    it('keeps exactly one widget token per session', function () {
        $user = makeUser();
        $first = sessionToken($user);
        $second = sessionToken($user);

        issueWidgetToken($this, $first);
        $latest = issueWidgetToken($this, $first);
        issueWidgetToken($this, $second);

        expect(PersonalAccessToken::where('parent_token_id', tokenRecord($first)->id)->pluck('id')->all())
            ->toBe([tokenRecord($latest)->id])
            ->and(PersonalAccessToken::where('parent_token_id', tokenRecord($second)->id)->count())->toBe(1);
    });

    it('refuses a cookie session without a personal access token', function () {
        $user = makeUser();
        $user->withAccessToken(new TransientToken());

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/widget-token');

        expectApiError($response, 403);
        $response->assertJsonPath('errors', ['personal_access_token_required']);
        expect(PersonalAccessToken::whereNotNull('parent_token_id')->count())->toBe(0);
    });

    it('answers 409 when a concurrent request wins the unique index', function () {
        $user = makeUser();
        $parentToken = sessionToken($user);
        $parent = tokenRecord($parentToken);

        $raced = false;
        Event::listen('eloquent.created: '.PersonalAccessToken::class, function (PersonalAccessToken $token) use ($user, $parent, &$raced) {
            if ($token->name === 'widget-token' && ! $raced) {
                $raced = true;
                $user->createToken('widget-token', ['widget'])->accessToken->forceFill(['parent_token_id' => $parent->id])->save();
            }
        });

        $response = bearer($this, $parentToken)->postJson('/api/v1/auth/widget-token');

        expectApiError($response, 409);
        $response->assertJsonPath('errors', ['widget_token_conflict']);
    });

    it('answers 409 when a concurrent request deadlocks the issue', function () {
        $user = makeUser();
        $parentToken = sessionToken($user);

        Event::listen('eloquent.created: '.PersonalAccessToken::class, function (PersonalAccessToken $token) {
            if ($token->name === 'widget-token') {
                throw new QueryException('mariadb', 'insert into personal_access_tokens', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        $response = bearer($this, $parentToken)->postJson('/api/v1/auth/widget-token');

        expectApiError($response, 409);
        $response->assertJsonPath('errors', ['widget_token_conflict']);
        expect(PersonalAccessToken::whereNotNull('parent_token_id')->count())->toBe(0);
    });
});

describe('Widget token lifecycle', function () {
    it('dies with its parent', function (callable $endSession) {
        $user = makeUser();
        $parentToken = sessionToken($user);
        $otherToken = sessionToken($user);
        $widgetId = tokenRecord(issueWidgetToken($this, $parentToken))->id;

        $endSession($this, $parentToken, $otherToken, tokenRecord($parentToken)->id);

        expect(PersonalAccessToken::find($widgetId))->toBeNull();
    })->with([
        'logout' => [fn ($test, $parent) => bearer($test, $parent)->postJson('/api/v1/auth/logout')->assertOk()],
        'revoke-token' => [fn ($test, $parent, $other, $parentId) => bearer($test, $other)->postJson('/api/v1/auth/revoke-token', ['token_id' => $parentId])->assertOk()],
        'logout-all' => [fn ($test, $parent) => bearer($test, $parent)->postJson('/api/v1/auth/logout-all')->assertOk()],
        'password change' => [fn ($test, $parent) => bearer($test, $parent)->putJson('/api/v1/users/change-password', [
            'current_password' => 'password',
            'new_password' => 'a-new-Password-123',
            'new_password_confirmation' => 'a-new-Password-123',
        ])->assertOk()],
        'refresh' => [fn ($test, $parent) => bearer($test, $parent)->postJson('/api/v1/auth/refresh')->assertOk()],
    ]);

    it('hides widget tokens from the session list and from revoke-token', function () {
        $user = makeUser();
        $parentToken = sessionToken($user);
        $widgetId = tokenRecord(issueWidgetToken($this, $parentToken))->id;

        $response = bearer($this, $parentToken)->getJson('/api/v1/auth/tokens');
        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', tokenRecord($parentToken)->id);

        expectApiError(bearer($this, $parentToken)->postJson('/api/v1/auth/revoke-token', ['token_id' => $widgetId]), 404);
        expect(PersonalAccessToken::find($widgetId))->not->toBeNull();
    });
});
