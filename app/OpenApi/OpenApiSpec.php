<?php

namespace App\OpenApi;

/**
 * @OA\Info(
 *     title="Solyto API",
 *     version="1.0.0",
 *     description="Solyto REST API — all responses are JSON wrapped in the standard envelope."
 * )
 *
 * @OA\Server(url="/api/v1", description="API Server")
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 *
 * --- STANDARD RESPONSE ENVELOPE SCHEMAS ---
 * Every endpoint wraps its payload inside one of these two schemas.
 *
 * @OA\Schema(
 *     schema="SuccessResponse",
 *
 *     @OA\Property(property="success", type="boolean", example=true),
 *     @OA\Property(property="message", type="string", example="Success"),
 *     @OA\Property(property="data", type="object", nullable=true),
 *     @OA\Property(property="timestamp", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="ErrorResponse",
 *
 *     @OA\Property(property="success", type="boolean", example=false),
 *     @OA\Property(property="message", type="string", example="An error occurred"),
 *     @OA\Property(property="timestamp", type="string", format="date-time"),
 *     @OA\Property(property="errors", type="object", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="PaginationMeta",
 *
 *     @OA\Property(property="current_page", type="integer"),
 *     @OA\Property(property="last_page", type="integer"),
 *     @OA\Property(property="per_page", type="integer"),
 *     @OA\Property(property="total", type="integer"),
 *     @OA\Property(property="from", type="integer", nullable=true),
 *     @OA\Property(property="to", type="integer", nullable=true),
 *     @OA\Property(property="has_more_pages", type="boolean")
 * )
 *
 * @OA\Schema(
 *     schema="ValidationErrorResponse",
 *
 *     @OA\Property(property="success", type="boolean", example=false),
 *     @OA\Property(property="message", type="string", example="Validation error"),
 *     @OA\Property(property="timestamp", type="string", format="date-time"),
 *     @OA\Property(property="errors", type="object")
 * )
 */
class OpenApiSpec {}
