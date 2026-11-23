<?php

use App\Api\Feeds\Jobs\ImportFeedSubscriptions;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedSubscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

// Error bodies are compared byte for byte, timestamp included.
beforeEach(fn () => $this->freezeTime());

function feedImportOpml(int $count = 2): string
{
    $outlines = '';

    for ($i = 1; $i <= $count; $i++) {
        $outlines .= '<outline text="Feed '.$i.'" xmlUrl="https://feed'.$i.'.example/rss"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><opml version="2.0"><body>'.$outlines.'</body></opml>';
}

function feedImportUpload($test, $user, string $content, string $name = 'feeds.opml')
{
    return $test->actingAs($user, 'sanctum')->post(
        '/api/v1/feeds/import',
        ['file' => UploadedFile::fake()->createWithContent($name, $content)],
        ['Accept' => 'application/json']
    );
}

/** @param  array<int, string>  $urls */
function feedImportSelect($test, $user, array $urls)
{
    return $test->actingAs($user, 'sanctum')->postJson('/api/v1/feeds/import/select', ['urls' => $urls]);
}

function feedImportState($test, $user)
{
    return $test->actingAs($user, 'sanctum')->getJson('/api/v1/feeds/import/state');
}

function expectFeedImportError($response, int $status, string $message): void
{
    expectApiError($response, $status);
    expect($response->getContent())->toBe(json_encode(['success' => false, 'message' => $message, 'timestamp' => now()->toISOString()]));
}

describe('Feed import upload', function () {
    it('parses the file and returns the state', function () {
        $user = makeUser();

        $response = feedImportUpload($this, $user, feedImportOpml());

        expectApiSuccess($response);
        $response->assertJsonPath('data.stage', 'parsed')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.feeds.0', [
                'url' => 'https://feed1.example/rss',
                'title' => 'Feed 1',
                'already_subscribed' => false,
                'status' => null,
            ]);

        feedImportState($this, $user)
            ->assertStatus(200)
            ->assertJsonPath('data.stage', 'parsed')
            ->assertJsonCount(2, 'data.feeds');
    });

    it('accepts an .xml file', function () {
        expectApiSuccess(feedImportUpload($this, makeUser(), feedImportOpml(), 'feeds.xml'));
    });

    it('sets already_subscribed for feeds the user has', function () {
        $user = makeUser();
        $feed = Feed::factory()->create(['url' => 'https://feed2.example/rss']);
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();

        feedImportUpload($this, $user, feedImportOpml())
            ->assertStatus(200)
            ->assertJsonPath('data.feeds.0.already_subscribed', false)
            ->assertJsonPath('data.feeds.1.already_subscribed', true);
    });

    it('rejects a file with a DTD and stores nothing from it', function () {
        $user = makeUser();
        $content = '<?xml version="1.0"?><!DOCTYPE opml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<opml version="2.0"><body><outline text="&xxe;" xmlUrl="https://xxe.example/feed"/></body></opml>';

        $response = feedImportUpload($this, $user, $content);

        expectFeedImportError($response, 422, 'This file could not be read as OPML.');
        expect($response->getContent())->not->toContain('root:');

        expectFeedImportError(feedImportState($this, $user), 404, 'No feed import found.');
    });

    it('rejects a UTF-16 file with a DTD that the byte check cannot see', function () {
        $user = makeUser();
        $content = "\xFF\xFE".mb_convert_encoding(
            '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE opml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<opml version="2.0"><body><outline text="&xxe;" xmlUrl="https://xxe.example/feed"/></body></opml>',
            'UTF-16LE',
            'UTF-8'
        );

        expectFeedImportError(feedImportUpload($this, $user, $content), 422, 'This file could not be read as OPML.');

        expectApiError(feedImportState($this, $user), 404);
    });

    it('rejects an unreadable file', function () {
        expectFeedImportError(feedImportUpload($this, makeUser(), 'this is not xml'), 422, 'This file could not be read as OPML.');
    });

    it('rejects a file without feeds', function () {
        expectFeedImportError(feedImportUpload($this, makeUser(), feedImportOpml(0)), 422, 'This file contains no feeds.');
    });

    it('rejects more than 500 feeds and accepts exactly 500', function () {
        expectFeedImportError(feedImportUpload($this, makeUser(), feedImportOpml(501)), 422, 'This file contains more than 500 feeds.');

        $response = feedImportUpload($this, makeUser(), feedImportOpml(500));
        expectApiSuccess($response);
        $response->assertJsonPath('data.total', 500);
    });

    it('rejects a file over 1 MB', function () {
        $response = $this->actingAs(makeUser(), 'sanctum')->post(
            '/api/v1/feeds/import',
            ['file' => UploadedFile::fake()->create('feeds.opml', 1025)],
            ['Accept' => 'application/json']
        );

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('file');
    });

    it('rejects a wrong extension', function () {
        $response = feedImportUpload($this, makeUser(), feedImportOpml(), 'feeds.txt');

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('file');
    });

    it('rejects a new upload while an import is running', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml())->assertStatus(200);
        feedImportSelect($this, $user, ['https://feed1.example/rss'])->assertStatus(200);

        expectFeedImportError(feedImportUpload($this, $user, feedImportOpml()), 409, 'A feed import is already running.');
    });

    it('returns 409 for an upload while a select holds the lock', function () {
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml(2))->assertStatus(200);
        $lock = Cache::lock('feed_import_select_'.$user->id, 10);
        $lock->get();

        expectFeedImportError(feedImportUpload($this, $user, feedImportOpml(3)), 409, 'A feed import is already running.');

        $lock->release();
        feedImportState($this, $user)->assertJsonPath('data.total', 2);
    });

    it('accepts a new upload once a running import has not saved progress for 5 minutes', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml(2))->assertStatus(200);
        feedImportSelect($this, $user, ['https://feed1.example/rss'])->assertStatus(200);

        $this->travel(299)->seconds();
        expectApiError(feedImportUpload($this, $user, feedImportOpml(3)), 409);

        $this->travel(2)->seconds();
        feedImportUpload($this, $user, feedImportOpml(3))
            ->assertStatus(200)
            ->assertJsonPath('data.stage', 'parsed')
            ->assertJsonPath('data.total', 3);
    });

    it('replaces a parsed import with a new upload', function () {
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml(2))->assertStatus(200);

        feedImportUpload($this, $user, feedImportOpml(3))->assertJsonPath('data.total', 3);
    });

    it('limits uploads to 20 per hour', function () {
        $user = makeUser();

        for ($i = 0; $i < 20; $i++) {
            expectApiError(feedImportUpload($this, $user, 'not xml'), 422);
        }

        expectApiError(feedImportUpload($this, $user, feedImportOpml()), 429);
    });
});

describe('Feed import select', function () {
    it('starts the import with the selected feeds only', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml(3));

        $response = feedImportSelect($this, $user, ['https://feed1.example/rss', 'https://feed3.example/rss', 'https://not-in-file.example/rss']);

        expectApiSuccess($response);
        $response->assertJsonPath('data.stage', 'running')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.feeds.0.url', 'https://feed1.example/rss')
            ->assertJsonPath('data.feeds.0.status', 'pending')
            ->assertJsonPath('data.feeds.1.url', 'https://feed3.example/rss');

        Queue::assertPushed(ImportFeedSubscriptions::class, 1);
    });

    it('drops feeds the user already subscribes to', function () {
        Queue::fake();
        $user = makeUser();
        $feed = Feed::factory()->create(['url' => 'https://feed1.example/rss']);
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();
        feedImportUpload($this, $user, feedImportOpml(2));

        feedImportSelect($this, $user, ['https://feed1.example/rss', 'https://feed2.example/rss'])
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.feeds.0.url', 'https://feed2.example/rss');
    });

    it('rejects a selection with no importable feed', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml());

        expectFeedImportError(
            feedImportSelect($this, $user, ['https://not-in-file.example/rss']),
            422,
            'None of the selected feeds can be imported.'
        );

        Queue::assertNothingPushed();
    });

    it('validates the payload', function () {
        $response = feedImportSelect($this, makeUser(), []);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('urls');
    });

    it('returns 404 without an import', function () {
        expectFeedImportError(feedImportSelect($this, makeUser(), ['https://feed1.example/rss']), 404, 'No feed import found.');
    });

    it('returns 409 when the import has already started', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml());
        feedImportSelect($this, $user, ['https://feed1.example/rss'])->assertStatus(200);

        expectFeedImportError(
            feedImportSelect($this, $user, ['https://feed2.example/rss']),
            409,
            'This feed import has already started.'
        );
    });

    it('returns 409 while another select holds the lock', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml());
        $lock = Cache::lock('feed_import_select_'.$user->id, 10);
        $lock->get();

        expectFeedImportError(
            feedImportSelect($this, $user, ['https://feed1.example/rss']),
            409,
            'This feed import has already started.'
        );
        Queue::assertNotPushed(ImportFeedSubscriptions::class);

        $lock->release();

        feedImportSelect($this, $user, ['https://feed1.example/rss'])->assertStatus(200);
        Queue::assertPushed(ImportFeedSubscriptions::class, 1);
    });

    it('starts no second job when the same selection is sent again after the import started', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml());

        feedImportSelect($this, $user, ['https://feed1.example/rss'])->assertStatus(200);
        expectApiError(feedImportSelect($this, $user, ['https://feed1.example/rss']), 409);

        Queue::assertPushed(ImportFeedSubscriptions::class, 1);
    });
});

describe('Feed import state', function () {
    it('returns 404 without an import', function () {
        expectFeedImportError(feedImportState($this, makeUser()), 404, 'No feed import found.');
    });

    it('keeps imports per user', function () {
        Queue::fake();
        $owner = makeUser();
        $other = makeUser();
        feedImportUpload($this, $owner, feedImportOpml());

        expectApiError(feedImportState($this, $other), 404);
        expectApiError(feedImportSelect($this, $other, ['https://feed1.example/rss']), 404);

        Queue::assertNothingPushed();
        feedImportState($this, $owner)
            ->assertStatus(200)
            ->assertJsonPath('data.stage', 'parsed');
    });
});

describe('Feed import errors', function () {
    it('answers them without reporting them', function () {
        Exceptions::fake();
        $user = makeUser();

        expectApiError(feedImportState($this, $user), 404);
        expectApiError(feedImportUpload($this, $user, feedImportOpml(0)), 422);
        feedImportUpload($this, $user, feedImportOpml())->assertStatus(200);
        Cache::lock('feed_import_select_'.$user->id, 10)->get();
        expectApiError(feedImportSelect($this, $user, ['https://feed1.example/rss']), 409);

        Exceptions::assertNothingReported();
    });
});
