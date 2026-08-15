<?php

use Illuminate\Support\Facades\Route;
use OpenApi\Generator as OpenApiGenerator;

/**
 * Parse the l5-swagger annotations (OpenApiSpec + controllers) and assert
 * that every documented operation/path resolves to a registered route with
 * a matching HTTP verb.
 *
 * The annotation set predates the current routing and documents several
 * legacy paths/verbs. Those are listed in KNOWN_DRIFT so the test stays
 * meaningful: any NEW drift between the OpenAPI spec and the route table
 * will fail this test.
 */
const OPENAPI_KNOWN_DRIFT = [
    'GET api/v1/calendars/widget/events',
    'PUT api/v1/calendars/{instanceId}/events/{eventUri}/occurrences/{occurrenceDate}',
    'DELETE api/v1/calendars/{instanceId}/events/{eventUri}/occurrences/{occurrenceDate}',
    'POST api/v1/calendars/{instanceId}/unsubscribe',
    'POST api/v1/calendars/invites/{token}/accept',
    'POST api/v1/calendars/invites/{token}/decline',
    'POST api/v1/calendars/import/start',
    'GET api/v1/check-ins',
    'POST api/v1/check-ins',
    'GET api/v1/clipboards',
    'POST api/v1/clipboards',
    'POST api/v1/clipboards/image',
    'GET api/v1/clipboards/{clipboard}/image',
    'DELETE api/v1/clipboards/{clipboard}',
    'GET api/v1/contacts/address-books',
    'POST api/v1/contacts/address-books',
    'POST api/v1/contacts/address-books/{addressBookId}',
    'PUT api/v1/contacts/address-books/{addressBookId}',
    'DELETE api/v1/contacts/address-books/{addressBookId}',
    'GET api/v1/contacts',
    'POST api/v1/contacts/photos',
    'PUT api/v1/contacts/address-books/{addressBookId}/{contactUri}',
    'DELETE api/v1/contacts/address-books/{addressBookId}/{contactUri}',
    'POST api/v1/contacts/address-books/{addressBookId}/{contactUri}/photo',
    'DELETE api/v1/contacts/address-books/{addressBookId}/{contactUri}/photo',
    'POST api/v1/contacts/import/start',
    'POST api/v1/contacts/import/select',
    'GET api/v1/contacts/import/state',
    'GET api/v1/feeds',
    'POST api/v1/feeds',
    'GET api/v1/feeds/{feedSubscription}',
    'PUT api/v1/feeds/{feedSubscription}',
    'DELETE api/v1/feeds/{feedSubscription}',
    'POST api/v1/feeds/search',
    'GET api/v1/finances/budgets',
    'POST api/v1/finances/budgets',
    'GET api/v1/finances/budgets/{budget}',
    'PUT api/v1/finances/budgets/{budget}',
    'DELETE api/v1/finances/budgets/{budget}',
    'GET api/v1/libraries/movies/search/{service}/{query}',
    'GET api/v1/libraries/music/search/deezer/{artist}/{album}',
    'GET api/v1/notifications/push/public-key',
    'GET api/v1/telegram/token',
    'GET api/v1/telegram/connection',
    'POST api/v1/telegram/confirm-token',
    'GET api/v1/time-tracking-categories',
    'POST api/v1/time-tracking-categories',
    'PUT api/v1/time-tracking-categories/{category}',
    'DELETE api/v1/time-tracking-categories/{category}',
    'GET api/v1/time-tracking-entries',
    'POST api/v1/time-tracking-entries',
    'PUT api/v1/time-tracking-entries/{entry}',
    'DELETE api/v1/time-tracking-entries/{entry}',
    'POST api/v1/time-tracking-entries/start',
    'POST api/v1/time-tracking-entries/{entry}/stop',
    'GET api/v1/time-tracking-entries/statistics',
    'GET api/v1/time-tracking-projects',
    'POST api/v1/time-tracking-projects',
    'GET api/v1/time-tracking-projects/{project}',
    'PUT api/v1/time-tracking-projects/{project}',
    'DELETE api/v1/time-tracking-projects/{project}',
    'POST api/v1/auth/logoutAll',
    'POST api/v1/auth/revokeToken',
    'POST api/v1/friends/requests/{id}/accept',
    'DELETE api/v1/friends/requests/{id}',
    'PUT api/v1/notifications/{id}/mark-read',
    'GET api/v1/users/{id}',
    'POST api/v1/users/update-profile-image',
    'GET api/v1/users/{id}/public-profile',
    'PUT api/v1/user-settings/navigation',
    'PUT api/v1/user-settings/language',
    'PUT api/v1/user-settings/timezone',
    'PUT api/v1/user-settings/date-format',
    'PUT api/v1/user-settings/time-format',
    'PUT api/v1/user-settings/weather-city',
    'PUT api/v1/user-settings/weather-temperature-unit',
    'PUT api/v1/user-settings/openai-api-key',
    'POST api/v1/user-settings/complete-onboarding',
    'GET api/v1/user-settings/check-in',
    'PUT api/v1/user-settings/check-in',
];

it('documents a route for every OpenAPI path operation', function () {
    $silentLogger = new class implements \Psr\Log\LoggerInterface
    {
        public function emergency($message, array $context = []): void {}

        public function alert($message, array $context = []): void {}

        public function critical($message, array $context = []): void {}

        public function error($message, array $context = []): void {}

        public function warning($message, array $context = []): void {}

        public function notice($message, array $context = []): void {}

        public function info($message, array $context = []): void {}

        public function debug($message, array $context = []): void {}

        public function log($level, $message, array $context = []): void {}
    };

    $openApi = (new OpenApiGenerator($silentLogger))->generate([
        base_path('app/OpenApi'),
        base_path('app/Api'),
    ]);

    $routes = Route::getRoutes();

    $normalize = function (string $path): string {
        $path = ltrim($path, '/');
        foreach (['api/v1/', 'api/', 'v1/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return 'api/v1/'.substr($path, strlen($prefix));
            }
        }

        return 'api/v1/'.$path;
    };

    $drift = [];

    foreach ($openApi->paths as $path) {
        $pathUri = $normalize($path->path);

        foreach ($path->operations() as $operation) {
            $method = strtolower($operation->method);
            $signature = strtoupper($method).' '.$pathUri;

            $matched = false;
            foreach ($routes->getRoutes() as $route) {
                if ($route->uri === $pathUri && in_array(strtoupper($method), $route->methods(), true)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $drift[] = $signature;
            }
        }
    }

    sort($drift);
    $known = OPENAPI_KNOWN_DRIFT;
    sort($known);

    expect($drift)->toBe($known, 'OpenAPI drift changed: '.implode(', ', array_diff($drift, $known)));
});
