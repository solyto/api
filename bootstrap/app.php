<?php

use App\Api\ApiResponse;
use App\Api\Calendars\Exceptions\CalendarAlreadyExistsException;
use App\Api\Calendars\Exceptions\CalendarReadOnlyException;
use App\Api\Calendars\Exceptions\ExternalCalendarDiscoveryException;
use App\Api\CheckIn\Exceptions\BuiltInTrackerException;
use App\Api\Clipboard\Exceptions\ClipboardFileTooLargeException;
use App\Api\Contacts\Exceptions\AddressBookAlreadyExistsException;
use App\Api\Feeds\Exceptions\FeedImportConflictException;
use App\Api\Feeds\Exceptions\FeedImportException;
use App\Api\Feeds\Exceptions\FeedImportNotFoundException;
use App\Api\Finances\Exceptions\LastBudgetValueException;
use App\Dav\Exceptions\ExternalCalendarPreconditionFailedException;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Exceptions\ImportConflictException;
use App\Dav\Exceptions\ImportException;
use App\Dav\Exceptions\ImportFileException;
use App\Dav\Exceptions\ImportTargetForbiddenException;
use App\Dav\Exceptions\UnreadableCalendarObjectException;
use App\Shared\Exceptions\LimitReachedException;
use App\Shared\Middleware\RecordClientVersion;
use App\Shared\Middleware\RecordRateLimitedRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
          ->withRouting(
              web: __DIR__.'/../routes/web.php',
              api: __DIR__.'/../routes/api.php',
              commands: __DIR__.'/../routes/console.php',
              health: '/up',
          )
          ->withMiddleware(function (Middleware $middleware) {
              if ($proxies = env('TRUSTED_PROXIES')) {
                  $middleware->trustProxies(at: $proxies);
              }
              $middleware->validateCsrfTokens(except: [
                  'webhooks/*',
              ]);
              $middleware->appendToGroup('api', RecordClientVersion::class);
              // Global, not in the api group: the router sorts ThrottleRequests ahead of group middleware, which would then never see the 429.
              $middleware->append(RecordRateLimitedRequests::class);
          })
          ->withCommands([
              __DIR__.'/../app/Api/Alerts/Commands',
              __DIR__.'/../app/Api/Contacts/Commands',
              __DIR__.'/../app/Api/Export/Commands',
              __DIR__.'/../app/Api/Feeds/Commands',
              __DIR__.'/../app/Api/Libraries/Commands',
              __DIR__.'/../app/Api/Notifications/Commands',
              __DIR__.'/../app/Api/Users/Commands',
              __DIR__.'/../app/Bots/Commands',
              __DIR__.'/../app/Plugins/Ai/Commands',
          ])
          ->withExceptions(function ($exceptions) {
              // RemoteCalDavClient, AppCalendarsPDO and the imports already log the DAV ones; none of these is an app error.
              $exceptions->dontReport([
                  ExternalCalendarDiscoveryException::class,
                  ExternalCalendarPreconditionFailedException::class,
                  ExternalCalendarUnavailableException::class,
                  ImportException::class,
                  ImportConflictException::class,
                  ImportFileException::class,
                  ImportTargetForbiddenException::class,
                  UnreadableCalendarObjectException::class,
                  LimitReachedException::class,
                  BuiltInTrackerException::class,
                  FeedImportException::class,
                  FeedImportConflictException::class,
                  FeedImportNotFoundException::class,
                  ClipboardFileTooLargeException::class,
                  LastBudgetValueException::class,
              ]);

              $exceptions->render(function (Throwable $e, Request $request) {
                  $isApi = $request->is('api/*') || $request->expectsJson();

                  if (!$isApi) {
                      return null;
                  }

                  if ($e instanceof ValidationException) {
                      return ApiResponse::validationError($e->errors(), 'The given data was invalid.');
                  }

                  if ($e instanceof AuthorizationException) {
                      return ApiResponse::forbidden($e->getMessage() ?: 'This action is unauthorized.');
                  }

                  if ($e instanceof AuthenticationException) {
                      return ApiResponse::unauthorized('Unauthenticated.');
                  }

                  if ($e instanceof ModelNotFoundException) {
                      $model = class_basename($e->getModel());
                      return ApiResponse::notFound("{$model} not found.");
                  }

                  if ($e instanceof NotFoundHttpException) {
                      return ApiResponse::notFound('The requested resource was not found.');
                  }

                  if ($e instanceof MethodNotAllowedHttpException) {
                      return ApiResponse::error('The specified method for the request is invalid.', 405);
                  }

                  if ($e instanceof QueryException) {
                      return ApiResponse::serverError('A database error occurred.');
                  }

                  if ($e instanceof ThrottleRequestsException) {
                      $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 0);

                      return ApiResponse::error('Too many requests. Please slow down.', 429, ['too_many_requests'], meta: ['retry_after' => $retryAfter ?: null])
                          ->withHeaders($e->getHeaders());
                  }

                  if ($e instanceof CalendarReadOnlyException) {
                      return ApiResponse::error($e->getMessage(), 403, ['calendar_read_only']);
                  }

                  if ($e instanceof HttpException) {
                      if ($e->getStatusCode() === 500 && str_contains($e->getMessage(), 'CORS')) {
                          return ApiResponse::error('CORS error occurred.', 500);
                      }
                      return ApiResponse::error($e->getMessage() ?: 'An error occurred.', $e->getStatusCode());
                  }

                  if ($e instanceof CalendarAlreadyExistsException) {
                      return ApiResponse::error('Calendar already exists.', 409);
                  }

                  if ($e instanceof AddressBookAlreadyExistsException) {
                      return ApiResponse::error('Address Book already exists.', 409);
                  }

                  if ($e instanceof ExternalCalendarDiscoveryException) {
                      return ApiResponse::error($e->getMessage(), 422, [$e->errorCode]);
                  }

                  if ($e instanceof ExternalCalendarPreconditionFailedException) {
                      return ApiResponse::error('The event was changed on its server.', 412, ['external_event_changed']);
                  }

                  if ($e instanceof ImportException) {
                      return ApiResponse::error($e->getMessage(), 404);
                  }

                  if ($e instanceof ImportConflictException) {
                      return ApiResponse::error($e->getMessage(), 409);
                  }

                  if ($e instanceof ImportFileException) {
                      return ApiResponse::error($e->getMessage(), 422);
                  }

                  if ($e instanceof ImportTargetForbiddenException) {
                      return ApiResponse::error($e->getMessage(), 403);
                  }

                  if ($e instanceof UnreadableCalendarObjectException) {
                      return ApiResponse::error('The event cannot be read, so it cannot be changed.', 422, ['external_event_unreadable']);
                  }

                  if ($e instanceof BuiltInTrackerException) {
                      return ApiResponse::error($e->getMessage(), 422);
                  }

                  if ($e instanceof LimitReachedException) {
                      return ApiResponse::error($e->getMessage(), 422, ['limit_reached']);
                  }

                  if ($e instanceof FeedImportException) {
                      return ApiResponse::error($e->getMessage(), 422);
                  }

                  if ($e instanceof FeedImportConflictException) {
                      return ApiResponse::error($e->getMessage(), 409);
                  }

                  if ($e instanceof FeedImportNotFoundException) {
                      return ApiResponse::error($e->getMessage(), 404);
                  }

                  if ($e instanceof ClipboardFileTooLargeException) {
                      return ApiResponse::error($e->getMessage(), 413);
                  }

                  if ($e instanceof LastBudgetValueException) {
                      return ApiResponse::error($e->getMessage(), 422);
                  }

                  if ($e instanceof ExternalCalendarUnavailableException) {
                      return ApiResponse::error('The calendar server is unavailable.', 503, ['external_server_unavailable']);
                  }

                  return ApiResponse::serverError('An unexpected error occurred.');
              });

              $exceptions->render(function (AuthenticationException $e, Request $request) {
                  if ($request->expectsJson() || $request->is('api/*')) {
                      return null;
                  }

                  return ApiResponse::unauthorized('Unauthenticated.');
              });
          })
          ->create();
