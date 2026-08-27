<?php

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Services\AiService;
use App\Api\Libraries\Services\LibraryRecommender;
use App\Api\Libraries\Services\LibraryReleases;
use App\Shared\Enums\AiUsageFeatureEnum;
use App\Shared\Models\AiUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a fake chat completion response.
 */
function fakeChatResponse(string $content, array $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]): \OpenAI\Responses\Chat\CreateResponse
{
    return \OpenAI\Responses\Chat\CreateResponse::from([
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'created' => time(),
        'model' => 'gpt-4o-mini',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => $usage,
    ], \OpenAI\Responses\Meta\MetaInformation::from([]));
}

/**
 * Build a mocked OpenAI client that returns the given responses for chat()->create().
 */
function mockedOpenAiClient($response): \OpenAI\Contracts\ClientContract
{
    $chat = Mockery::mock(\OpenAI\Contracts\Resources\ChatContract::class);
    $chat->shouldReceive('create')->andReturn($response);

    $client = Mockery::mock(\OpenAI\Contracts\ClientContract::class);
    $client->shouldReceive('chat')->andReturn($chat);

    return $client;
}

describe('AiService', function () {
    it('respond returns the chat message content and tracks usage', function () {
        $service = app()->makeWith(AiService::class, ['client' => mockedOpenAiClient(fakeChatResponse('Hello there!'))]);

        $result = $service->respond('You are helpful', 'Hi');

        expect($result)->toBe('Hello there!');
    });

    it('respond handles an array of messages', function () {
        $service = app()->makeWith(AiService::class, ['client' => mockedOpenAiClient(fakeChatResponse('ok'))]);

        $result = $service->respond('System', [
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'two'],
        ]);

        expect($result)->toBe('ok');
    });

    it('respondStructured decodes the json response', function () {
        $service = app()->makeWith(AiService::class, ['client' => mockedOpenAiClient(fakeChatResponse(json_encode(['recommendations' => []])))]);

        $result = $service->respondStructured('Prompt', 'input', ['type' => 'json_schema']);

        expect($result)->toBe(['recommendations' => []]);
    });

    it('saveUsageForUser persists the tracked usage', function () {
        $user = makeUser();
        $service = app()->makeWith(AiService::class, ['client' => mockedOpenAiClient(fakeChatResponse('x'))]);

        $service->respond('Prompt', 'Input');
        $service->saveUsageForUser($user, AiUsageFeatureEnum::ASSISTANT_CHAT);

        $usage = AiUsage::where('user_id', $user->id)->first();
        expect($usage)->not->toBeNull();
        expect($usage->model)->toBe('gpt-4o-mini');
        expect($usage->input_tokens)->toBe(10);
        expect($usage->output_tokens)->toBe(5);
        expect($usage->total_tokens)->toBe(15);
        expect($usage->feature)->toBe(AiUsageFeatureEnum::ASSISTANT_CHAT->value);
    });
});

describe('LibraryRecommender', function () {
    it('throws for types without a recommender', function () {
        expect(fn () => new LibraryRecommender(LibraryTypeEnum::MOVIE, makeUser()))
            ->toThrow(\Exception::class);
    });

    it('recommends a favorite book', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Frank Herbert', 'title' => 'Dune']);

        $recommender = new LibraryRecommender(LibraryTypeEnum::BOOK, $user);

        $favorite = $recommender->favorite();

        expect($favorite['title'])->toBe('Dune');
        expect($favorite['creator'])->toBe('Frank Herbert');
    });

    it('recommends an unrated book', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => null, 'title' => 'Unrated']);

        $recommender = new LibraryRecommender(LibraryTypeEnum::BOOK, $user);

        expect($recommender->unrated()['title'])->toBe('Unrated');
    });

    it('returns null for random when the library is empty', function () {
        $user = makeUser();

        $recommender = new LibraryRecommender(LibraryTypeEnum::BOOK, $user);

        expect($recommender->random())->toBeNull();
    });

    it('new() calls the AI service with favorites and saves usage', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Frank Herbert', 'title' => 'Dune']);

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('respondStructured')->once()->andReturn([
            'recommendations' => [['title' => 'Children of Dune', 'creator' => 'Frank Herbert', 'genre' => 'Sci-Fi']],
        ]);
        $ai->shouldReceive('saveUsageForUser')->once()->with($user, AiUsageFeatureEnum::LIBRARY_RECOMMENDER);

        $recommender = new LibraryRecommender(LibraryTypeEnum::BOOK, $user);

        $result = $recommender->new();

        expect($result['recommendations'][0]['title'])->toBe('Children of Dune');
    });

    it('new() returns null without favorites', function () {
        $user = makeUser();

        $recommender = new LibraryRecommender(LibraryTypeEnum::BOOK, $user);

        expect($recommender->new())->toBeNull();
    });
});

describe('LibraryReleases', function () {
    it('collects music releases from deezer for favorite artists', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $deezer = $this->mock(\App\Api\Libraries\Services\External\DeezerService::class);
        $deezer->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => 6]]);
        $deezer->shouldReceive('getNewReleases')->with(6)->andReturn([[
            'id' => 1,
            'title' => 'New Album',
            'link' => 'https://deezer.com/album/1',
            'cover_big' => 'https://cover.example/1.jpg',
            'release_date' => now()->toDateString(),
        ]]);

        $service = app()->makeWith(LibraryReleases::class, [
            'user' => $user,
            'deezerService' => $deezer,
        ]);

        $releases = $service->getMusicReleases();

        expect($releases)->toHaveCount(1);
        expect($releases[0]->getTitle())->toBe('New Album');
        expect($releases[0]->getArtist())->toBe('Radiohead');
    });

    it('skips artists with no new releases', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $deezer = $this->mock(\App\Api\Libraries\Services\External\DeezerService::class);
        $deezer->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => 6]]);
        $deezer->shouldReceive('getNewReleases')->with(6)->andReturn(null);

        $service = app()->makeWith(LibraryReleases::class, [
            'user' => $user,
            'deezerService' => $deezer,
        ]);

        expect($service->getMusicReleases())->toBe([]);
    });

    it('collects spotify music releases for favorite artists', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $spotify = $this->mock(\App\Api\Libraries\Services\External\SpotifyService::class);
        $spotify->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => '5K4W6rqBFWDnAN6FQUkS6x']]);
        $spotify->shouldReceive('getNewReleases')->with('5K4W6rqBFWDnAN6FQUkS6x')->andReturn([[
            'id' => '4aawyAB9vmqN3uQ7FjRGTy',
            'name' => 'Fresh Album',
            'images' => [['url' => 'https://cover.example/fresh.jpg']],
            'release_date' => now()->toDateString(),
            'release_date_precision' => 'day',
        ]]);

        $service = app()->makeWith(LibraryReleases::class, [
            'user' => $user,
            'spotifyService' => $spotify,
        ]);

        $releases = $service->getSpotifyMusicReleases();

        expect($releases)->toHaveCount(1);
        expect($releases[0]->getTitle())->toBe('Fresh Album');
        expect($releases[0]->getArtist())->toBe('Radiohead');
        expect($releases[0]->getProvider())->toBe(\App\Api\Libraries\Enums\MusicServiceEnum::SPOTIFY->value);
        expect($releases[0]->getId())->toBe('4aawyAB9vmqN3uQ7FjRGTy');
        expect($releases[0]->getArtistId())->toBe('5K4W6rqBFWDnAN6FQUkS6x');
    });

    it('skips artists with no spotify releases', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $spotify = $this->mock(\App\Api\Libraries\Services\External\SpotifyService::class);
        $spotify->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => '5K4W6rqBFWDnAN6FQUkS6x']]);
        $spotify->shouldReceive('getNewReleases')->with('5K4W6rqBFWDnAN6FQUkS6x')->andReturn(null);

        $service = app()->makeWith(LibraryReleases::class, [
            'user' => $user,
            'spotifyService' => $spotify,
        ]);

        expect($service->getSpotifyMusicReleases())->toBe([]);
    });

    it('collects book releases from hardcover for favorite authors', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Frank Herbert']);

        $hardcover = $this->mock(\App\Api\Libraries\Services\External\HardcoverService::class);
        $hardcover->shouldReceive('getNewReleases')->with('Frank Herbert')->andReturn([[
            'id' => 1,
            'slug' => 'children-of-dune',
            'title' => 'Children of Dune',
            'release_date' => now()->toDateString(),
            'description' => 'Third book',
            'pages' => 444,
            'image' => ['url' => 'https://cover.example/cod.jpg'],
            'contributions' => [['author' => ['id' => 1, 'name' => 'Frank Herbert']]],
        ]]);

        $service = app()->makeWith(LibraryReleases::class, [
            'user' => $user,
            'hardcoverService' => $hardcover,
        ]);

        $releases = $service->getBookReleases();

        expect($releases)->toHaveCount(1);
        expect($releases[0]->getTitle())->toBe('Children of Dune');
        expect($releases[0]->getAuthor())->toBe('Frank Herbert');
    });
});
