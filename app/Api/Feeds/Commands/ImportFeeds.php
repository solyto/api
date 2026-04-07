<?php

namespace App\Api\Feeds\Commands;

use App\Api\Feeds\Services\FeedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportFeeds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:feeds:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Feeds';

    private FeedService $feedService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->feedService = app(FeedService::class);
        $this->importFromGit();
    }

    private function importFromGit(): void
    {
        $urls = [
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/Australia.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/Canada.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/France.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/Germany.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/Iran.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/Ireland.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/Italy.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/Japan.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/South Africa.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/Spain.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/United Kingdom.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/countries/without_category/United States.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Android Development.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Android.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Apple.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Architecture.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Beauty.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Books.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Business & Economy.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Cars.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Cricket.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/DIY.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Fashion.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Food.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Football.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Funny.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Gaming.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/History.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Interior design.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Movies.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Music.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/News.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Personal finance.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Photography.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Programming.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Science.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Space.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Sports.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Startups.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Tech.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Television.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Tennis.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Travel.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/UI - UX.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/Web Development.opml',
            'https://raw.githubusercontent.com/plenaryapp/awesome-rss-feeds/master/recommended/without_category/iOS Development.opml',
        ];

        foreach ($urls as $url) {
            $this->info("Importing feeds from {$url}");
            $response = Http::get($url);
            $feeds = $this->parseOpml($response->body());

            foreach ($feeds as $f) {
                if ($this->feedService->addFeed($f['title'], $f['url'])) {
                    $this->info("Feed {$f['url']} imported successfully!");
                } else {
                    $this->error("Failed to import feed {$f['url']}");
                }
            }
        }
    }

    private function parseOpml(string $content): array {
        $content = preg_replace('/&(?!(?:[a-zA-Z]+|#[0-9]+|#x[0-9a-fA-F]+);)/', '&amp;', $content);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, null, LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING);

        if ($xml === false || $xml->body === null) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $message = !empty($errors) ? trim($errors[0]->message) : 'unknown error';
            throw new \RuntimeException("Failed to parse OPML: " . $message);
        }

        libxml_clear_errors();

        $entries = [];

        foreach ($xml->body->outline as $feed) {
            $entries[] = [
                'title'       => (string) $feed['title'],
                'description' => (string) $feed['description'],
                'url'         => (string) $feed['xmlUrl'],
                'type'        => (string) $feed['type'],
            ];
        }

        return $entries;
    }
}
