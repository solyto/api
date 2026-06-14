<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MusicSearchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->getId(),
            'title'        => $this->getTitle(),
            'artist'       => $this->getArtist(),
            'cover'        => $this->getCover(),
            'release_year' => $this->getReleaseYear(),
            'provider'     => $this->getProvider(),
            'url'          => $this->getUrl(),
        ];
    }
}
