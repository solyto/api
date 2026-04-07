<?php

namespace App\Api\Weather\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="DailyWeatherForecast",
 *
 *     @OA\Property(property="city", type="string"),
 *     @OA\Property(property="configured", type="boolean"),
 *     @OA\Property(property="today", type="object",
 *         @OA\Property(property="code", type="integer"),
 *         @OA\Property(property="sunrise", type="string", format="date-time"),
 *         @OA\Property(property="sunset", type="string", format="date-time"),
 *         @OA\Property(property="uv_index", type="number"),
 *         @OA\Property(property="temperature_max", type="number"),
 *         @OA\Property(property="temperature_min", type="number"),
 *         @OA\Property(property="rain", type="number"),
 *         @OA\Property(property="wind", type="number"),
 *         @OA\Property(property="snowfall", type="number"),
 *         @OA\Property(property="clouds", type="number"),
 *         @OA\Property(property="humidity", type="number")
 *     ),
 *     @OA\Property(property="current", type="object",
 *         @OA\Property(property="code", type="integer"),
 *         @OA\Property(property="temperature", type="number"),
 *         @OA\Property(property="humidity", type="number"),
 *         @OA\Property(property="wind", type="number"),
 *         @OA\Property(property="wind_direction", type="number"),
 *         @OA\Property(property="clouds", type="number"),
 *         @OA\Property(property="rain", type="number"),
 *         @OA\Property(property="snowfall", type="number")
 *     )
 * )
 */
class DailyWeatherForecastResource extends JsonResource
{
    public function __construct($resource, private readonly ?string $city = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'city' => $this->city,
            'configured' => true,
            'today' => [
                'code' => $this['daily']['weather_code'][0],
                'sunrise' => $this['daily']['sunrise'][0],
                'sunset' => $this['daily']['sunset'][0],
                'uv_index' => $this['daily']['uv_index_max'][0],
                'temperature_max' => $this['daily']['temperature_2m_max'][0],
                'temperature_min' => $this['daily']['temperature_2m_min'][0],
                'rain' => $this['daily']['rain_sum'][0],
                'wind' => $this['daily']['wind_speed_10m_max'][0],
                'snowfall' => $this['daily']['snowfall_sum'][0],
                'clouds' => $this['daily']['cloud_cover_mean'][0],
                'humidity' => $this['daily']['relative_humidity_2m_mean'][0],
            ],
            'current' => [
                'code' => $this['current']['weather_code'],
                'temperature' => $this['current']['temperature_2m'],
                'humidity' => $this['current']['relative_humidity_2m'],
                'wind' => $this['current']['wind_speed_10m'],
                'wind_direction' => $this['current']['wind_direction_10m'],
                'clouds' => $this['current']['cloud_cover'],
                'rain' => $this['current']['rain'],
                'snowfall' => $this['current']['snowfall'],
            ],
        ];
    }
}
