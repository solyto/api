<?php

namespace App\Api\Libraries\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ImdbApiService
{
    private const GET_MOVIE_URL = 'https://api.imdbapi.dev/titles/%s';

    public function getMovie(string $title): ?array
    {
        try {
            $response = Http::get(sprintf(self::GET_MOVIE_URL, $title));

            if (!$response->successful()) {
                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            return null;
        }
    }
}
