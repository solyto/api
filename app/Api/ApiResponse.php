<?php

namespace App\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiResponse
{
    public static function success(
        $data = null,
        string $message = 'Success',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => self::transformData($data),
            'timestamp' => now()->toISOString(),
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        if (self::isPaginated($data)) {
            $response['meta'] = array_merge($meta, self::getPaginationMeta($data));
        }

        return response()->json($response, $status);
    }

    public static function error(
        string $message = 'An error occurred',
        int $status = 400,
        $errors = null,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => now()->toISOString(),
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status);
    }

    public static function validationError($errors, string $message = 'Validation failed'): JsonResponse
    {
        return self::error($message, 422, $errors);
    }

    public static function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): JsonResponse {
        return self::error($message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return self::error($message, 404);
    }

    public static function serverError(string $message = 'Internal server error'): JsonResponse
    {
        return self::error($message, 500);
    }

    private static function transformData($data)
    {
        if ($data instanceof ResourceCollection) {
            return $data->resolve();
        }

        if ($data instanceof JsonResource) {
            return $data->resolve();
        }

        if (self::isPaginated($data)) {
            return $data->items();
        }

        return $data;
    }

    private static function isPaginated($data): bool
    {
        return $data instanceof LengthAwarePaginator;
    }


    private static function getPaginationMeta($data): array
    {
        if (!self::isPaginated($data)) {
            return [];
        }

        return [
            'pagination' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'from' => $data->firstItem(),
                'to' => $data->lastItem(),
                'has_more_pages' => $data->hasMorePages(),
            ]
        ];
    }
}
