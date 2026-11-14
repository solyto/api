<?php

namespace App\Api\Feeds\DTOs;

use App\Api\Feeds\Enums\FeedImportStage;
use App\Api\Feeds\Enums\FeedImportStatus;

class FeedImportStateDTO
{
    /**
     * @param  array<int, array{url: string, title: string, already_subscribed: bool, status: ?FeedImportStatus}>  $feeds
     */
    public function __construct(
        public FeedImportStage $stage,
        public array $feeds,
    ) {}

    public function total(): int
    {
        return count($this->feeds);
    }

    public function processed(): int
    {
        return count(array_filter(
            $this->feeds,
            fn (array $feed) => $feed['status'] !== null && $feed['status'] !== FeedImportStatus::Pending
        ));
    }
}
