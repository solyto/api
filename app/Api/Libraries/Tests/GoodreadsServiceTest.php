<?php

use App\Api\Libraries\DTOs\BookReleaseDTO;
use App\Api\Libraries\Enums\BookServiceEnum;
use App\Api\Libraries\Services\External\GoodreadsService;
use Illuminate\Support\Facades\Http;

covers(GoodreadsService::class);

function goodreadsFixture(): string
{
    return file_get_contents(__DIR__.'/../../../../tests/Fixtures/goodreads_book.html');
}

describe('GoodreadsService', function () {
    it('imports a book from a goodreads url', function () {
        Http::fake([
            'goodreads.com/*' => Http::response(goodreadsFixture()),
        ]);

        $dto = app(GoodreadsService::class)->importFromUrl('https://www.goodreads.com/book/show/1-dune');

        expect($dto)->toBeInstanceOf(BookReleaseDTO::class);
        expect($dto->getTitle())->toBe('Dune');
        expect($dto->getAuthor())->toBe('Frank Herbert');
        expect($dto->getPageCount())->toBe(412);
        expect($dto->getReleaseDate()?->format('Y-m-d'))->toBe('1965-08-01');
        expect($dto->getCover())->toBe('https://cover.example/dune.jpg');
        expect($dto->getProvider())->toBe(BookServiceEnum::GOODREADS->value);
        expect($dto->getUrl())->toBe('https://www.goodreads.com/book/show/1-dune');
    });

    it('filters out translators from the authors', function () {
        Http::fake([
            'goodreads.com/*' => Http::response(goodreadsFixture()),
        ]);

        $dto = app(GoodreadsService::class)->importFromUrl('https://www.goodreads.com/book/show/1-dune');

        expect($dto)->not->toBeNull();
        expect($dto->getAuthor())->toBe('Frank Herbert');
    });

    it('returns null when the request fails', function () {
        Http::fake([
            'goodreads.com/*' => Http::response('', 500),
        ]);

        $dto = app(GoodreadsService::class)->importFromUrl('https://www.goodreads.com/book/show/1');

        expect($dto)->toBeNull();
    });

    it('returns null when the body is empty', function () {
        Http::fake([
            'goodreads.com/*' => Http::response('', 200),
        ]);

        $dto = app(GoodreadsService::class)->importFromUrl('https://www.goodreads.com/book/show/1');

        expect($dto)->toBeNull();
    });
});
