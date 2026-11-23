<?php

namespace App\Api\CheckIn\Enums;

// Cases are in the order the trackers have always been shown in; new users start with it.
enum CheckInBuiltInTracker: string
{
    case Mood = 'mood';
    case Sleep = 'sleep';
    case Dreams = 'dreams';
    case FoodQuality = 'food_quality';
    case FoodAmount = 'food_amount';
    case Water = 'water';
    case Sports = 'sports';
    case Work = 'work';
    case Menstruation = 'menstruation';
    case Alcohol = 'alcohol';
    case Smoking = 'smoking';
    case SocialLife = 'social_life';

    public const array DEFAULT_ENABLED = [self::Mood, self::Sports, self::Water, self::Sleep, self::Dreams, self::Work, self::FoodQuality, self::FoodAmount];

    public const array DEFAULT_SPORTS = ['dumbbell', 'bike', 'mountain', 'footprints', 'waves_ladder'];

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_map(fn (self $tracker) => $tracker->value, self::cases());
    }

    public function type(): CheckInTrackerType
    {
        return $this === self::Sports ? CheckInTrackerType::Sport : CheckInTrackerType::Scale;
    }

    public function position(): int
    {
        return array_search($this, self::cases(), true);
    }
}
