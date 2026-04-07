<?php

namespace App\Api\Weather\Services;

use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Http;

class WeatherService
{
    private const string API_BASE_URL = 'https://api.open-meteo.com/v1/forecast';
    private const string CACHE_KEY = 'weather_forecast';
    private const int CACHE_TTL = 900;

    public function __construct(private readonly UserCacheService $cache) {}

    public function getHourlyForecast(float $latitude, float $longitude, string $timezone): array
    {
        return $this->cache->remember(
            [self::CACHE_KEY, 'hourly', $this->getCacheIdentifier($latitude, $longitude)],
            self::CACHE_TTL,
            fn() => $this->fetchHourlyForecast($latitude, $longitude, $timezone)
        );
    }

    public function getDailyForecast(float $latitude, float $longitude, string $timezone): array
    {
        return $this->cache->remember(
            [self::CACHE_KEY, 'daily', $this->getCacheIdentifier($latitude, $longitude)],
            self::CACHE_TTL,
            fn() => $this->fetchDailyForecast($latitude, $longitude, $timezone)
        );
    }

    private function fetchHourlyForecast(float $latitude, float $longitude, string $timezone): array
    {
        $url = self::API_BASE_URL . '?' . http_build_query([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'hourly' => 'temperature_2m,relative_humidity_2m,rain,wind_speed_10m,cloud_cover,weather_code',
            'timezone' => $timezone,
            'forecast_days' => 1,
        ]);

        $res = Http::get($url);

        if (!$res->successful()) {
            return [];
        }

        return $res->json();
    }

    private function fetchDailyForecast(float $latitude, float $longitude, string $timezone): array
    {
        $url = self::API_BASE_URL . '?' . http_build_query([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'daily' => 'weather_code,sunrise,sunset,uv_index_max,temperature_2m_max,temperature_2m_min,rain_sum,snowfall_sum,cloud_cover_mean,wind_speed_10m_max,relative_humidity_2m_mean',
            'current' => 'temperature_2m,relative_humidity_2m,rain,cloud_cover,wind_speed_10m,wind_direction_10m,weather_code,snowfall,is_day',
            'timezone' => $timezone,
            'forecast_days' => 1,
        ]);

        $res = Http::get($url);

        if (!$res->successful()) {
            return [];
        }

        return $res->json();
    }

    private function getCacheIdentifier(float $latitude, float $longitude): string
    {
        $lat = round($latitude, 4);
        $lon = round($longitude, 4);
        return "{$lat}_{$lon}";
    }
}
