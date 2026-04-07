<?php

namespace App\Api\Libraries\Enums;

enum LibraryRecommendationEnum: string
{
    case FAVORITE = 'favorite';
    case RANDOM = 'random';
    case UNRATED = 'unrated';
    case NEW = 'new';
}
