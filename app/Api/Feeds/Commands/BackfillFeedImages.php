<?php

namespace App\Api\Feeds\Commands;

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Services\FeedService;
use Illuminate\Console\Command;

class BackfillFeedImages extends Command
{
    protected $signature = 'app:feeds:backfill-images';
    protected $description = 'Backfill image_url for existing feed items that have none';

    public function handle(FeedService $feedService): int
    {
        $feeds = Feed::has('subscriptions')->get();

        $this->info("Syncing {$feeds->count()} feeds...");
        $bar = $this->output->createProgressBar($feeds->count());
        $bar->start();

        foreach ($feeds as $feed) {
            $feedService->syncFeed($feed->id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return Command::SUCCESS;
    }
}
