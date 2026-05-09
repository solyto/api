<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Api\ApiResponse;

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
          })
          ->withCommands([
              __DIR__.'/../app/Api/Export/Commands',
              __DIR__.'/../app/Api/Feeds/Commands',
              __DIR__.'/../app/Api/Libraries/Commands',
              __DIR__.'/../app/Api/Notifications/Commands',
              __DIR__.'/../app/Api/Users/Commands',
              __DIR__.'/../app/Bots/Commands',
          ])
          ->withExceptions(function ($exceptions) {
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
                      return ApiResponse::error('Too many requests. Please slow down.', 429);
                  }

                  if ($e instanceof HttpException) {
                      if ($e->getStatusCode() === 500 && str_contains($e->getMessage(), 'CORS')) {
                          return ApiResponse::error('CORS error occurred.', 500);
                      }
                      return ApiResponse::error($e->getMessage() ?: 'An error occurred.', $e->getStatusCode());
                  }

                  return ApiResponse::serverError('An unexpected error occurred.');
              });

              $exceptions->render(function (AuthenticationException $e, Request $request) {
                  if ($request->expectsJson() || $request->is('api/*')) {
                      return null;
                  }

                  return redirect()->guest(route('login'));
              });
          })
          ->create();
