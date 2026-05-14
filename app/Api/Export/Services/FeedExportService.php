<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\Users\Models\User;

class FeedExportService
{
    public function export(User $user, string $path): void
    {
        $subscriptions = FeedSubscription::where('user_id', $user->id)->with('feed')->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<opml version="1.0">'."\n"
            .'    <head>'."\n"
            .'        <title>Solyto Feeds Export</title>'."\n"
            .'    </head>'."\n"
            .'    <body>'."\n";

        foreach ($subscriptions as $sub) {
            $title = htmlspecialchars($sub->title ?? $sub->feed?->title ?? '');
            $url = htmlspecialchars($sub->feed?->url ?? '');
            $xml .= '        <outline type="rss" text="'.$title.'" title="'.$title.'" xmlUrl="'.$url.'" />'."\n";
        }

        $xml .= '    </body>'."\n"
            .'</opml>';

        file_put_contents($path, $xml);
    }
}
