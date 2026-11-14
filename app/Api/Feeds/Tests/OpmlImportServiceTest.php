<?php

use App\Api\Feeds\Exceptions\FeedImportException;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Feeds\Services\OpmlImportService;

covers(OpmlImportService::class);

function opmlBody(string $body): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><opml version="2.0"><head><title>Export</title></head><body>'.$body.'</body></opml>';
}

/**
 * @return array<int, array{url: string, title: string}>
 */
function opmlParsed(string $content): array
{
    return array_map(
        fn (array $feed) => ['url' => $feed['url'], 'title' => $feed['title']],
        app(OpmlImportService::class)->start(makeUser(), $content)->feeds
    );
}

describe('OPML parsing: shapes', function () {
    it('reads a flat list', function () {
        expect(opmlParsed(opmlBody(
            '<outline type="rss" text="One" title="One" xmlUrl="https://one.example/feed" htmlUrl="https://one.example"/>'
            .'<outline type="rss" text="Two" title="Two" xmlUrl="https://two.example/rss"/>'
        )))->toBe([
            ['url' => 'https://one.example/feed', 'title' => 'One'],
            ['url' => 'https://two.example/rss', 'title' => 'Two'],
        ]);
    });

    it('flattens one level of folders (Miniflux)', function () {
        $content = '<?xml version="1.0" encoding="UTF-8"?><opml version="2.0" xmlns:miniflux="https://miniflux.app"><body>'
            .'<outline text="Tech"><outline title="One" text="One" xmlUrl="https://one.example/feed" miniflux:keeplist="(?i)php"/></outline>'
            .'<outline text="News"><outline title="Two" text="Two" xmlUrl="https://two.example/feed"/></outline>'
            .'</body></opml>';

        expect(opmlParsed($content))->toBe([
            ['url' => 'https://one.example/feed', 'title' => 'One'],
            ['url' => 'https://two.example/feed', 'title' => 'Two'],
        ]);
    });

    it('walks deep nesting and reads xmlURL (NewsBlur)', function () {
        expect(opmlParsed(opmlBody(
            '<outline text="A"><outline text="B"><outline text="C">'
            .'<outline text="Deep" title="Deep" type="rss" xmlURL="https://deep.example/feed"/>'
            .'</outline></outline></outline>'
        )))->toBe([['url' => 'https://deep.example/feed', 'title' => 'Deep']]);
    });

    it('uses text when there is no title and skips scraper types (FreshRSS)', function () {
        $content = '<?xml version="1.0" encoding="UTF-8"?><opml version="2.0" xmlns:frss="https://freshrss.org/opml"><body><outline text="Blogs">'
            .'<outline text="Only Text" type="rss" xmlUrl="https://text.example/feed" frss:cssFullContent="article"/>'
            .'<outline text="Scraped" type="HTML+XPath" xmlUrl="https://scraped.example/page" frss:xPathItem="//div"/>'
            .'</outline></body></opml>';

        expect(opmlParsed($content))->toBe([['url' => 'https://text.example/feed', 'title' => 'Only Text']]);
    });

    it('reads the url attribute (Thunderbird)', function () {
        expect(opmlParsed(opmlBody('<outline title="Bird" text="Bird" type="rss" url="https://bird.example/feed"/>')))
            ->toBe([['url' => 'https://bird.example/feed', 'title' => 'Bird']]);
    });

    it('ignores non-outline elements in the body (Feeder)', function () {
        $content = '<?xml version="1.0" encoding="UTF-8"?><opml version="1.1" xmlns:feeder="https://nononsenseapps.com/feeder"><body>'
            .'<feeder:settings><feeder:setting key="pref_sync" value="true"/></feeder:settings>'
            .'<outline title="Feeder" text="Feeder" type="rss" xmlUrl="https://feeder.example/feed"/>'
            .'</body></opml>';

        expect(opmlParsed($content))->toBe([['url' => 'https://feeder.example/feed', 'title' => 'Feeder']]);
    });
});

describe('OPML parsing: content', function () {
    it('keeps an unescaped ampersand in a URL intact', function () {
        expect(opmlParsed(opmlBody('<outline text="Amp" xmlUrl="https://amp.example/feed?a=1&b=2"/>'))[0]['url'])
            ->toBe('https://amp.example/feed?a=1&b=2');
    });

    it('decodes an ISO-8859-1 file', function () {
        $content = mb_convert_encoding(
            '<?xml version="1.0" encoding="ISO-8859-1"?><opml version="2.0"><body><outline text="Café" xmlUrl="https://cafe.example/feed"/></body></opml>',
            'ISO-8859-1',
            'UTF-8'
        );

        expect(opmlParsed($content)[0]['title'])->toBe('Café');
    });

    it('accepts a UTF-8 byte order mark', function () {
        expect(opmlParsed("\xEF\xBB\xBF".opmlBody('<outline text="Bom" xmlUrl="https://bom.example/feed"/>')))
            ->toBe([['url' => 'https://bom.example/feed', 'title' => 'Bom']]);
    });

    it('turns an entity-encoded HTML title into plain text', function () {
        expect(opmlParsed(opmlBody('<outline text="&lt;b&gt;Tom &amp;amp; Jerry&lt;/b&gt;" xmlUrl="https://tom.example/feed"/>'))[0]['title'])
            ->toBe('Tom & Jerry');
    });

    it('falls back to the URL when there is no title', function () {
        expect(opmlParsed(opmlBody('<outline xmlUrl="https://untitled.example/feed"/>'))[0]['title'])
            ->toBe('https://untitled.example/feed');
    });

    it('truncates long titles to 255 characters', function () {
        expect(mb_strlen(opmlParsed(opmlBody('<outline text="'.str_repeat('é', 300).'" xmlUrl="https://long.example/feed"/>'))[0]['title']))
            ->toBe(255);
    });
});

describe('OPML parsing: filtering', function () {
    it('collapses duplicates, first occurrence wins', function () {
        expect(opmlParsed(opmlBody(
            '<outline text="A"><outline text="First" xmlUrl="https://dup.example/feed"/></outline>'
            .'<outline text="B"><outline text="Second" xmlUrl="https://dup.example/feed"/></outline>'
        )))->toBe([['url' => 'https://dup.example/feed', 'title' => 'First']]);
    });

    it('drops non-http URLs', function () {
        expect(opmlParsed(opmlBody(
            '<outline text="Ftp" xmlUrl="ftp://ftp.example/feed"/>'
            .'<outline text="File" xmlUrl="file:///etc/passwd"/>'
            .'<outline text="Ok" xmlUrl="http://ok.example/feed"/>'
        )))->toBe([['url' => 'http://ok.example/feed', 'title' => 'Ok']]);
    });

    it('drops URLs longer than 255 characters and keeps one of exactly 255', function () {
        $prefix = 'https://len.example/';
        $exact = $prefix.str_repeat('a', 255 - strlen($prefix));
        $tooLong = $exact.'a';

        expect(array_column(opmlParsed(opmlBody(
            '<outline text="Long" xmlUrl="'.$tooLong.'"/><outline text="Exact" xmlUrl="'.$exact.'"/>'
        )), 'url'))->toBe([$exact]);
    });

    it('rejects a file whose feeds were all filtered out', function () {
        opmlParsed(opmlBody('<outline text="Folder"><outline text="Ftp" xmlUrl="ftp://ftp.example/feed"/></outline>'));
    })->throws(FeedImportException::class, 'This file contains no feeds.');
});

describe('OPML parsing: already subscribed', function () {
    it('marks the feeds the user already subscribes to', function () {
        $user = makeUser();
        $mine = Feed::factory()->create(['url' => 'https://mine.example/feed']);
        $theirs = Feed::factory()->create(['url' => 'https://theirs.example/feed']);
        FeedSubscription::factory()->forUser($user)->forFeed($mine)->create();
        FeedSubscription::factory()->forUser(makeUser())->forFeed($theirs)->create();

        $state = app(OpmlImportService::class)->start($user, opmlBody(
            '<outline text="Mine" xmlUrl="https://mine.example/feed"/>'
            .'<outline text="Theirs" xmlUrl="https://theirs.example/feed"/>'
            .'<outline text="New" xmlUrl="https://new.example/feed"/>'
        ));

        expect(array_column($state->feeds, 'already_subscribed', 'url'))->toBe([
            'https://mine.example/feed' => true,
            'https://theirs.example/feed' => false,
            'https://new.example/feed' => false,
        ]);
    });
});
