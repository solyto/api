<?php

use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryBookGenre;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryGameGenre;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMovieGenre;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryMusicGenre;
use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Tags\Models\Tag;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('LibraryBook Factory', function () {
    it('creates a valid book', function () {
        $user = User::factory()->create();
        $book = LibraryBook::factory()->create();

        expect($book->title)->toBeString();
        expect($book->author)->toBeString();
        expect($book->rating)->toBeInt();
        expect($book->rating)->toBeGreaterThanOrEqual(1);
        expect($book->rating)->toBeLessThanOrEqual(5);
        expect($book->user_id)->toBe($user->id);
    });

    it('creates a completed book', function () {
        $user = User::factory()->create();
        $book = LibraryBook::factory()->forUser($user)->completed()->create();

        expect($book->completed_at)->not()->toBeNull();
    });

    it('creates an in progress book', function () {
        $user = User::factory()->create();
        $book = LibraryBook::factory()->forUser($user)->inProgress()->create();

        expect($book->started_at)->not()->toBeNull();
        expect($book->finished_at)->toBeNull();
    });

    it('creates a wishlist book', function () {
        $book = LibraryBook::factory()->wishlist()->create();

        expect($book->wishlist)->toBeTrue();
    });

    it('creates a lent book', function () {
        $book = LibraryBook::factory()->lent()->create();

        expect($book->lent_to)->not()->toBeNull();
    });

    it('creates a book with custom title', function () {
        $book = LibraryBook::factory()->withTitle('The Great Gatsby')->create();

        expect($book->title)->toBe('The Great Gatsby');
    });

    it('creates a book with current page', function () {
        $book = LibraryBook::factory()->create([
            'current_page' => 100,
            'pages' => 300,
        ]);

        expect($book->current_page)->toBe(100);
        expect($book->pages)->toBe(300);
    });

    it('creates a book for user', function () {
        $user = User::factory()->create();
        $book = LibraryBook::factory()->forUser($user)->create();

        expect($book->user_id)->toBe($user->id);
    });

    it('can have tags', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->forUser($user)->create();
        $book = LibraryBook::factory()->forUser($user)->create();
        $book->tags()->attach($tag);

        expect($book->tags)->toHaveCount(1);
    });

    it('can have genres', function () {
        $user = User::factory()->create();
        $genre = LibraryBookGenre::factory()->forUser($user)->create();
        $book = LibraryBook::factory()->forUser($user)->create();
        $book->genres()->attach($genre);

        expect($book->genres)->toHaveCount(1);
    });
});

describe('LibraryMusic Factory', function () {
    it('creates a valid music', function () {
        $user = User::factory()->create();
        $music = LibraryMusic::factory()->create();

        expect($music->title)->toBeString();
        expect($music->artist)->toBeString();
        expect($music->rating)->toBeInt();
        expect($music->rating)->toBeGreaterThanOrEqual(1);
        expect($music->rating)->toBeLessThanOrEqual(5);
        expect($music->user_id)->toBe($user->id);
    });

    it('creates an album', function () {
        $music = LibraryMusic::factory()->album()->create();

        expect($music->type)->toBe('album');
    });

    it('creates a single', function () {
        $music = LibraryMusic::factory()->single()->create();

        expect($music->type)->toBe('single');
    });

    it('creates an ep', function () {
        $music = LibraryMusic::factory()->create([
            'type' => 'ep',
        ]);

        expect($music->type)->toBe('ep');
    });

    it('creates a compilation', function () {
        $music = LibraryMusic::factory()->create([
            'type' => 'compilation',
        ]);

        expect($music->type)->toBe('compilation');
    });

    it('creates a wishlist music', function () {
        $music = LibraryMusic::factory()->wishlist()->create();

        expect($music->wishlist)->toBeTrue();
    });

    it('creates a vinyl music', function () {
        $music = LibraryMusic::factory()->vinyl()->create();

        expect($music->format)->toBe('vinyl');
    });

    it('creates a cd music', function () {
        $music = LibraryMusic::factory()->create([
            'format' => 'cd',
        ]);

        expect($music->format)->toBe('cd');
    });

    it('creates a digital music', function () {
        $music = LibraryMusic::factory()->create([
            'format' => 'digital',
        ]);

        expect($music->format)->toBe('digital');
    });

    it('creates music for user', function () {
        $user = User::factory()->create();
        $music = LibraryMusic::factory()->forUser($user)->create();

        expect($music->user_id)->toBe($user->id);
    });

    it('can have genres', function () {
        $user = User::factory()->create();
        $genre = LibraryMusicGenre::factory()->forUser($user)->create();
        $music = LibraryMusic::factory()->forUser($user)->create();
        $music->genres()->attach($genre);

        expect($music->genres)->toHaveCount(1);
    });
});

describe('LibraryMovie Factory', function () {
    it('creates a valid movie', function () {
        $user = User::factory()->create();
        $movie = LibraryMovie::factory()->create();

        expect($movie->title)->toBeString();
        expect($movie->rating)->toBeInt();
        expect($movie->rating)->toBeGreaterThanOrEqual(1);
        expect($movie->rating)->toBeLessThanOrEqual(5);
        expect($movie->user_id)->toBe($user->id);
    });

    it('creates a completed movie', function () {
        $user = User::factory()->create();
        $movie = LibraryMovie::factory()->forUser($user)->completed()->create();

        expect($movie->finished_at)->not()->toBeNull();
    });

    it('creates an in progress movie', function () {
        $user = User::factory()->create();
        $movie = LibraryMovie::factory()->forUser($user)->inProgress()->create();

        expect($movie->started_at)->not()->toBeNull();
        expect($movie->finished_at)->toBeNull();
    });

    it('creates a wishlist movie', function () {
        $movie = LibraryMovie::factory()->wishlist()->create();

        expect($movie->wishlist)->toBeTrue();
    });

    it('creates a movie for user', function () {
        $user = User::factory()->create();
        $movie = LibraryMovie::factory()->forUser($user)->create();

        expect($movie->user_id)->toBe($user->id);
    });

    it('can have tags', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->forUser($user)->create();
        $movie = LibraryMovie::factory()->forUser($user)->create();
        $movie->tags()->attach($tag);

        expect($movie->tags)->toHaveCount(1);
    });

    it('can have genres', function () {
        $user = User::factory()->create();
        $genre = LibraryMovieGenre::factory()->forUser($user)->create();
        $movie = LibraryMovie::factory()->forUser($user)->create();
        $movie->genres()->attach($genre);

        expect($movie->genres)->toHaveCount(1);
    });
});

describe('LibraryGame Factory', function () {
    it('creates a valid game', function () {
        $user = User::factory()->create();
        $game = LibraryGame::factory()->create();

        expect($game->title)->toBeString();
        expect($game->rating)->toBeInt();
        expect($game->rating)->toBeGreaterThanOrEqual(1);
        expect($game->rating)->toBeLessThanOrEqual(5);
        expect($game->user_id)->toBe($user->id);
    });

    it('creates a completed game', function () {
        $user = User::factory()->create();
        $game = LibraryGame::factory()->forUser($user)->completed()->create();

        expect($game->finished_at)->not()->toBeNull();
    });

    it('creates an in progress game', function () {
        $user = User::factory()->create();
        $game = LibraryGame::factory()->forUser($user)->inProgress()->create();

        expect($game->started_at)->not()->toBeNull();
        expect($game->finished_at)->toBeNull();
    });

    it('creates a wishlist game', function () {
        $game = LibraryGame::factory()->wishlist()->create();

        expect($game->wishlist)->toBeTrue();
    });

    it('creates a game with playtime', function () {
        $game = LibraryGame::factory()->create([
            'playtime_hours' => 10,
        ]);

        expect($game->playtime_hours)->toBe(10.0);
    });

    it('creates a PC game', function () {
        $game = LibraryGame::factory()->withPlatform('PC')->create();

        expect($game->platform)->toBe('PC');
    });

    it('creates a PlayStation game', function () {
        $game = LibraryGame::factory()->withPlatform('PlayStation')->create();

        expect($game->platform)->toBe('PlayStation');
    });

    it('creates a Nintendo Switch game', function () {
        $game = LibraryGame::factory()->withPlatform('Nintendo Switch')->create();

        expect($game->platform)->toBe('Nintendo Switch');
    });

    it('creates a game for user', function () {
        $user = User::factory()->create();
        $game = LibraryGame::factory()->forUser($user)->create();

        expect($game->user_id)->toBe($user->id);
    });

    it('can have tags', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->forUser($user)->create();
        $game = LibraryGame::factory()->forUser($user)->create();
        $game->tags()->attach($tag);

        expect($game->tags)->toHaveCount(1);
    });

    it('can have genres', function () {
        $user = User::factory()->create();
        $genre = LibraryGameGenre::factory()->forUser($user)->create();
        $game = LibraryGame::factory()->forUser($user)->create();
        $game->genres()->attach($genre);

        expect($game->genres)->toHaveCount(1);
    });
});

describe('LibraryQuote Factory', function () {
    it('creates a valid quote', function () {
        $user = User::factory()->create();
        $quote = LibraryQuote::factory()->create();

        expect($quote->quote)->toBeString();
        expect($quote->author)->toBeString();
        expect($quote->user_id)->toBe($user->id);
    });

    it('creates a quote with custom author', function () {
        $quote = LibraryQuote::factory()->withAuthor('Albert Einstein')->create();

        expect($quote->author)->toBe('Albert Einstein');
    });

    it('creates a quote with custom quote', function () {
        $quote = LibraryQuote::factory()->withQuote('Be the change you wish to see in the world.')->create();

        expect($quote->quote)->toBe('Be the change you wish to see in the world.');
    });

    it('creates a quote with source', function () {
        $quote = LibraryQuote::factory()->withSource('A Book')->create();

        expect($quote->source)->toBe('A Book');
    });

    it('creates an inspirational quote', function () {
        $quote = LibraryQuote::factory()->inspirational()->create();

        expect($quote->quote)->toContain('great', 'change', 'believe');
    });

    it('creates a quote for user', function () {
        $user = User::factory()->create();
        $quote = LibraryQuote::factory()->forUser($user)->create();

        expect($quote->user_id)->toBe($user->id);
    });

    it('can have tags', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->forUser($user)->create();
        $quote = LibraryQuote::factory()->forUser($user)->create();
        $quote->tags()->attach($tag);

        expect($quote->tags)->toHaveCount(1);
    });
});

describe('LibraryRecipe Factory', function () {
    it('creates a valid recipe', function () {
        $recipe = LibraryRecipe::factory()->create();

        expect($recipe->title)->toBeString();
        expect($recipe->rating)->toBeInt();
        expect($recipe->rating)->toBeGreaterThanOrEqual(1);
        expect($recipe->rating)->toBeLessThanOrEqual(5);
        expect($recipe->time_to_make)->toBeInt();
        expect($recipe->user_id)->not()->toBeNull();
    });

    it('creates a quick recipe', function () {
        $recipe = LibraryRecipe::factory()->quick()->create();

        expect($recipe->time_to_make)->toBeLessThan(30);
    });

    it('creates a time consuming recipe', function () {
        $recipe = LibraryRecipe::factory()->timeConsuming()->create();

        expect($recipe->time_to_make)->toBeGreaterThan(60);
    });

    it('creates a breakfast recipe', function () {
        $recipe = LibraryRecipe::factory()->withType('breakfast')->create();

        expect($recipe->type)->toBe('breakfast');
    });

    it('creates a lunch recipe', function () {
        $recipe = LibraryRecipe::factory()->withType('lunch')->create();

        expect($recipe->type)->toBe('lunch');
    });

    it('creates a dinner recipe', function () {
        $recipe = LibraryRecipe::factory()->withType('dinner')->create();

        expect($recipe->type)->toBe('dinner');
    });

    it('creates a dessert recipe', function () {
        $recipe = LibraryRecipe::factory()->withType('dessert')->create();

        expect($recipe->type)->toBe('dessert');
    });

    it('creates a snack recipe', function () {
        $recipe = LibraryRecipe::factory()->withType('snack')->create();

        expect($recipe->type)->toBe('snack');
    });

    it('creates a recipe for user', function () {
        $user = User::factory()->create();
        $recipe = LibraryRecipe::factory()->forUser($user)->create();

        expect($recipe->user_id)->toBe($user->id);
    });
});

describe('LibraryLink Factory', function () {
    it('creates a valid link', function () {
        $user = User::factory()->create();
        $link = LibraryLink::factory()->create();

        expect($link->title)->toBeString();
        expect($link->url)->toBeString();
        expect($link->user_id)->not()->toBeNull();
    });

    it('creates a favorite link', function () {
        $link = LibraryLink::factory()->favorite()->create();

        expect($link->is_favorite)->toBeTrue();
    });

    it('creates a non-favorite link', function () {
        $link = LibraryLink::factory()->notFavorite()->create();

        expect($link->is_favorite)->toBeFalse();
    });

    it('creates a link with custom url', function () {
        $url = 'https://example.com';
        $link = LibraryLink::factory()->withUrl($url)->create();

        expect($link->url)->toBe($url);
    });

    it('creates a link for user', function () {
        $user = User::factory()->create();
        $link = LibraryLink::factory()->forUser($user)->create();

        expect($link->user_id)->toBe($user->id);
    });

    it('can have tags', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->forUser($user)->create();
        $link = LibraryLink::factory()->forUser($user)->create();
        $link->tags()->attach($tag);

        expect($link->tags)->toHaveCount(1);
    });

    it('can belong to category', function () {
        $user = User::factory()->create();
        $category = LibraryLinkCategory::factory()->forUser($user)->create();
        $link = LibraryLink::factory()->forUser($user)->forCategory($category)->create();

        expect($link->category_id)->toBe($category->id);
    });
});

describe('LibraryBookGenre Factory', function () {
    it('creates a valid genre', function () {
        $user = User::factory()->create();
        $genre = LibraryBookGenre::factory()->forUser($user)->create();

        expect($genre->title)->toBeString();
        expect($genre->user_id)->toBe($user->id);
    });
});

describe('LibraryMusicGenre Factory', function () {
    it('creates a valid genre', function () {
        $user = User::factory()->create();
        $genre = LibraryMusicGenre::factory()->forUser($user)->create();

        expect($genre->title)->toBeString();
        expect($genre->user_id)->toBe($user->id);
    });
});

describe('LibraryMovieGenre Factory', function () {
    it('creates a valid genre', function () {
        $user = User::factory()->create();
        $genre = LibraryMovieGenre::factory()->forUser($user)->create();

        expect($genre->title)->toBeString();
        expect($genre->user_id)->toBe($user->id);
    });
});

describe('LibraryGameGenre Factory', function () {
    it('creates a valid genre', function () {
        $user = User::factory()->create();
        $genre = LibraryGameGenre::factory()->forUser($user)->create();

        expect($genre->title)->toBeString();
        expect($genre->user_id)->toBe($user->id);
    });
});

describe('LibraryLinkCategory Factory', function () {
    it('creates a valid category', function () {
        $user = User::factory()->create();
        $category = LibraryLinkCategory::factory()->create();

        expect($category->title)->toBeString();
        expect($category->color)->toBeString();
        expect($category->user_id)->toBe($user->id);
    });

    it('creates a category with custom title', function () {
        $category = LibraryLinkCategory::factory()->withTitle('Work')->create();

        expect($category->title)->toBe('Work');
    });

    it('creates a category with custom color', function () {
        $category = LibraryLinkCategory::factory()->withColor('#FF0000')->create();

        expect($category->color)->toBe('#FF0000');
    });

    it('creates a category for user', function () {
        $user = User::factory()->create();
        $category = LibraryLinkCategory::factory()->forUser($user)->create();

        expect($category->user_id)->toBe($user->id);
    });
});
