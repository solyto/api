<?php

namespace App\Api\Weather\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Weather\Resources\DailyWeatherForecastResource;
use App\Api\Weather\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeatherController
{
    use HandlesApiAuth;

    public function __construct(private readonly WeatherService $weatherService) {}

    /**
     * @OA\Get(
     *     path="/api/weather/today",
     *     operationId="weatherToday",
     *     tags={"Weather"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Weather forecast retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/DailyWeatherForecast"),
     *             @OA\Property(property="message", type="string", example="Weather forecast retrieved successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings;

        if (!$settings || !$settings->weather_latitude || !$settings->weather_longitude) {
            return ApiResponse::success([
                'city' => null,
                'configured' => false,
            ], 'Weather location not configured.');
        }

        $forecast = $this->weatherService->getDailyForecast(
            latitude: (float) $settings->weather_latitude,
            longitude: (float) $settings->weather_longitude,
            timezone: $settings->timezone ?? 'Europe/Berlin'
        );

        if (empty($forecast)) {
            return ApiResponse::serverError();
        }

        return ApiResponse::success(
            new DailyWeatherForecastResource($forecast, $settings->weather_city, $settings->temperature_unit ?? 'c'),
            'Weather forecast retrieved successfully.'
        );
    }
}
