<?php

namespace App\Api\Dashboard\Enums;

enum QuickAddContentType: string
{
    case Music = 'music';
    case Books = 'books';
    case Movies = 'movies';
    case Games = 'games';
    case Links = 'links';
    case Recipes = 'recipes';
    case Plants = 'plants';
    case Quotes = 'quotes';
    case Todo = 'todo';
    case Note = 'note';
    case Feed = 'feed';
    case Clipboard = 'clipboard';
}
