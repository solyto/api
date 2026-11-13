<?php

namespace App\Api\Feeds\Enums;

enum FeedImportStatus: string
{
    case Pending = 'pending';
    case Subscribed = 'subscribed';
    case AlreadySubscribed = 'already_subscribed';
    case Unreachable = 'unreachable';
    case NotAFeed = 'not_a_feed';
}
