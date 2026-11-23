<?php

namespace App\Api\Libraries\DTOs;

/**
 * @OA\Schema(
 *     schema="LibrarySearchMeta",
 *
 *     @OA\Property(property="unavailable", type="boolean", description="True when the provider could not be reached or answered with an error; data is then empty although the provider may have matches")
 * )
 */
class SearchResultsDTO
{
    /**
     * @param  array<array-key, BookSearchResultDTO|GameSearchResultDTO|MovieSearchResultDTO|MusicSearchResultDTO>  $results
     */
    public function __construct(
        public readonly array $results,
        public readonly bool $unavailable,
    ) {}

    // The providers' search methods return null when the provider could not be asked or did not answer usably, [] when it found nothing.
    public static function fromProvider(?array $results): self
    {
        return new self($results ?? [], $results === null);
    }
}
