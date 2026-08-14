<?php

use App\Api\Users\Models\UserSettings;
use App\Api\Weather\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

describe('GET /api/v1/weather/today', function () {
    it('returns not configured when the user has no weather location', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/weather/today')
            ->assertStatus(200)
            ->assertJsonPath('data.configured', false);
    });

    it('returns the forecast for a configured user', function () {
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response([
                'latitude' => 52.52,
                'longitude' => 13.41,
                'current' => ['temperature_2m' => 21.3, 'weather_code' => 1],
                'daily' => [
                    'time' => [now()->toDateString()],
                    'weather_code' => [1],
                    'sunrise' => ['05:00'],
                    'sunset' => ['21:00'],
                    'uv_index_max' => [5.0],
                    'temperature_2m_max' => [24.0],
                    'temperature_2m_min' => [15.0],
                    'rain_sum' => [0.0],
                    'snowfall_sum' => [0.0],
                    'cloud_cover_mean' => [20.0],
                    'wind_speed_10m_max' => [12.0],
                    'relative_humidity_2m_mean' => [60.0],
                ],
                'current' => [
                    'temperature_2m' => 21.3,
                    'relative_humidity_2m' => 55.0,
                    'rain' => 0.0,
                    'cloud_cover' => 10.0,
                    'wind_speed_10m' => 8.0,
                    'wind_direction_10m' => 180,
                    'weather_code' => 1,
                    'snowfall' => 0.0,
                    'is_day' => 1,
                ],
            ]),
        ]);

        $user = makeUser();
        $user->settings()->update([
            'weather_city' => 'Berlin',
            'weather_latitude' => 52.52,
            'weather_longitude' => 13.41,
            'timezone' => 'Europe/Berlin',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/weather/today')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.city', 'Berlin');
    });
});

describe('WeatherService', function () {
    it('returns an empty array when the api fails', function () {
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response([], 500),
        ]);

        $result = app(WeatherService::class)->getDailyForecast(52.52, 13.41, 'Europe/Berlin');

        expect($result)->toBe([]);
    });

    it('caches the hourly forecast per location', function () {
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response([
                'hourly' => ['time' => ['2026-08-13T00:00'], 'temperature_2m' => [18]],
            ]),
        ]);

        $service = app(WeatherService::class);

        $first = $service->getHourlyForecast(52.52, 13.41, 'Europe/Berlin');
        $second = $service->getHourlyForecast(52.52, 13.41, 'Europe/Berlin');

        expect($first['hourly']['temperature_2m'])->toBe([18]);
        expect($second)->toBe($first);
        Http::assertSentCount(1);
    });

    it('uses distinct cache keys for different coordinates', function () {
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response([
                'daily' => ['time' => [now()->toDateString()]],
            ]),
        ]);

        $service = app(WeatherService::class);

        $service->getDailyForecast(52.52, 13.41, 'Europe/Berlin');
        $service->getDailyForecast(48.85, 2.35, 'Europe/Paris');

        Http::assertSentCount(2);
    });
});
