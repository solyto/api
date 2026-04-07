<?php

namespace App\Api\Weather\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="HourlyWeatherForecast",
 *
 *     @OA\Property(property="temperature", type="array", @OA\Items(type="number")),
 *     @OA\Property(property="humidity", type="array", @OA\Items(type="number")),
 *     @OA\Property(property="rain", type="array", @OA\Items(type="number")),
 *     @OA\Property(property="wind", type="array", @OA\Items(type="number")),
 *     @OA\Property(property="clouds", type="array", @OA\Items(type="number")),
 *     @OA\Property(property="code", type="array", @OA\Items(type="integer"))
 * )
 */
class HourlyWeatherForecastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'temperature' => $this['hourly']['temperature_2m'],
            'humidity' => $this['hourly']['relative_humidity_2m'],
            'rain' => $this['hourly']['rain'],
            'wind' => $this['hourly']['wind_speed_10m'],
            'clouds' => $this['hourly']['cloud_cover'],
            'code' => $this['hourly']['weather_code'],
        ];
    }
}
