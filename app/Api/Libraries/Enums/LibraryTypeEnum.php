<?php

namespace App\Api\Libraries\Enums;

enum LibraryTypeEnum: string
{
    case MUSIC = 'music';
    case BOOK = 'books';
    case MOVIE = 'movies';
    case GAME = 'games';
    case QUOTE = 'quotes';
    case RECIPE = 'recipes';
    case LINK = 'links';
    case PLANT = 'plants';

    public function storageFolderName(): string
    {
        return match($this) {
            self::MUSIC => 'music',
            self::BOOK => 'books',
            self::MOVIE => 'movies',
            self::GAME => 'games',
            self::QUOTE => 'quotes',
            self::RECIPE => 'recipes',
            self::LINK => 'links',
            self::PLANT => 'plants',
        };
    }

    public function hasRecommender(): bool
    {
        return match($this) {
            self::MUSIC, self::BOOK                                              => true,
            self::MOVIE, self::GAME, self::QUOTE, self::RECIPE, self::LINK, self::PLANT => false,
        };
    }

    public function getModel(): string
    {
        return match($this) {
            self::MUSIC => \App\Api\Libraries\Models\LibraryMusic::class,
            self::BOOK => \App\Api\Libraries\Models\LibraryBook::class,
            self::MOVIE => \App\Api\Libraries\Models\LibraryMovie::class,
            self::GAME => \App\Api\Libraries\Models\LibraryGame::class,
            self::QUOTE => \App\Api\Libraries\Models\LibraryQuote::class,
            self::RECIPE => \App\Api\Libraries\Models\LibraryRecipe::class,
            self::LINK => \App\Api\Libraries\Models\LibraryLink::class,
            self::PLANT => \App\Api\Libraries\Models\LibraryPlant::class,
        };
    }
}
