<?php

use App\Api\Feeds\Jobs\ImportFeedSubscriptions;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedSubscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

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

describe('Feed import upload', function () {
    it('parses the file and returns the state', function () {
        $user = makeUser();

        feedImportUpload($this, $user, feedImportOpml())->assertStatus(200)->assertJsonPath('success', true)
            ->assertJsonPath('data.stage', 'parsed')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.feeds.0', [
                'url' => 'https://feed1.example/rss',
                'title' => 'Feed 1',
                'already_subscribed' => false,
                'status' => null,
            ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/import/state')
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

        expectApiError($response, 422);
        expect($response->getContent())->not->toContain('root:');

        expectApiError($this->actingAs($user, 'sanctum')->getJson('/api/v1/feeds/import/state'), 404);
    });

    it('rejects a UTF-16 file with a DTD that the byte check cannot see', function () {
        $user = makeUser();
        $content = "\xFF\xFE".mb_convert_encoding(
            '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE opml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<opml version="2.0"><body><outline text="&xxe;" xmlUrl="https://xxe.example/feed"/></body></opml>',
            'UTF-16LE',
            'UTF-8'
        );

        feedImportUpload($this, $user, $content)->assertStatus(422)->assertJsonPath('success', false);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/feeds/import/state')->assertStatus(404);
    });

    it('rejects an unreadable file', function () {
        expectApiError(feedImportUpload($this, makeUser(), 'this is not xml'), 422);
    });

    it('rejects a file without feeds', function () {
        expectApiError(feedImportUpload($this, makeUser(), feedImportOpml(0)), 422);
    });

    it('rejects more than 500 feeds and accepts exactly 500', function () {
        expectApiError(feedImportUpload($this, makeUser(), feedImportOpml(501)), 422);
        feedImportUpload($this, makeUser(), feedImportOpml(500))->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('data.total', 500);
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
        feedImportUpload($this, makeUser(), feedImportOpml(), 'feeds.txt')->assertStatus(422)->assertJsonPath('success', false)
            ->assertJsonValidationErrors('file');
    });

    it('rejects a new upload while an import is running', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml())->assertStatus(200);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/import/select', ['urls' => ['https://feed1.example/rss']])
            ->assertStatus(200);

        expectApiError(feedImportUpload($this, $user, feedImportOpml()), 409);
    });

    it('replaces a parsed import with a new upload', function () {
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml(2))->assertStatus(200);

        feedImportUpload($this, $user, feedImportOpml(3))->assertJsonPath('data.total', 3);
    });

    it('limits uploads to 20 per hour', function () {
        $user = makeUser();

        for ($i = 0; $i < 20; $i++) {
            feedImportUpload($this, $user, 'not xml')->assertStatus(422);
        }

        expectApiError(feedImportUpload($this, $user, feedImportOpml()), 429);
    });
});

describe('Feed import select', function () {
    it('starts the import with the selected feeds only', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml(3));

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/feeds/import/select', [
            'urls' => ['https://feed1.example/rss', 'https://feed3.example/rss', 'https://not-in-file.example/rss'],
        ])->assertStatus(200)->assertJsonPath('success', true)
            ->assertJsonPath('data.stage', 'running')
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

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/import/select', ['urls' => ['https://feed1.example/rss', 'https://feed2.example/rss']])
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.feeds.0.url', 'https://feed2.example/rss');
    });

    it('rejects a selection with no importable feed', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml());

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/import/select', ['urls' => ['https://not-in-file.example/rss']]), 422);

        Queue::assertNothingPushed();
    });

    it('validates the payload', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/feeds/import/select', ['urls' => []])->assertStatus(422)->assertJsonPath('success', false)
            ->assertJsonValidationErrors('urls');
    });

    it('returns 404 without an import', function () {
        expectApiError($this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/feeds/import/select', ['urls' => ['https://feed1.example/rss']]), 404);
    });

    it('returns 409 when the import has already started', function () {
        Queue::fake();
        $user = makeUser();
        feedImportUpload($this, $user, feedImportOpml());
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/import/select', ['urls' => ['https://feed1.example/rss']])
            ->assertStatus(200);

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/import/select', ['urls' => ['https://feed2.example/rss']]), 409);
    });
});

describe('Feed import state', function () {
    it('returns 404 without an import', function () {
        expectApiError($this->actingAs(makeUser(), 'sanctum')->getJson('/api/v1/feeds/import/state'), 404);
    });

    it('keeps imports per user', function () {
        Queue::fake();
        $owner = makeUser();
        $other = makeUser();
        feedImportUpload($this, $owner, feedImportOpml());

        expectApiError($this->actingAs($other, 'sanctum')->getJson('/api/v1/feeds/import/state'), 404);
        expectApiError($this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/feeds/import/select', ['urls' => ['https://feed1.example/rss']]), 404);

        Queue::assertNothingPushed();
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/feeds/import/state')
            ->assertStatus(200)
            ->assertJsonPath('data.stage', 'parsed');
    });
});
