<?php

namespace App\Api\Libraries\Services\External;

use Calliostro\Discogs\ClientFactory;
use Calliostro\Discogs\DiscogsApiClient;

class DiscogsApiService
{
    private readonly DiscogsApiClient $client;

    public function __construct()
    {
        $this->client = ClientFactory::create();
    }

    public function search(string $query): ?array
    {
        $result = $this->client->search([
            'q' => $query,
            'type' => 'release',
        ]);

        return $result['results'] ?? null;
    }

    public function getRelease(int $releaseId)
    {
        return $this->client->releaseGet([
            'id' => $releaseId
        ]);
    }
}
