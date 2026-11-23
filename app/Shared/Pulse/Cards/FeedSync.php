<?php

namespace App\Shared\Pulse\Cards;

use App\Shared\Pulse\PulseMetrics;
use Illuminate\Contracts\Support\Renderable;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

#[Lazy]
class FeedSync extends MetricCard
{
    #[Url(as: 'feed-sync')]
    public string $orderBy = 'slowest';

    public function render(): Renderable
    {
        [$rows, $time, $runAt] = $this->remember(fn () => $this->merged([
            'runs' => [PulseMetrics::FEED_SYNC, 'count'],
            'average' => [PulseMetrics::FEED_SYNC, 'avg'],
            'slowest' => [PulseMetrics::FEED_SYNC, 'max'],
            'lookups' => [PulseMetrics::FEED_OG_LOOKUPS, 'sum'],
            'lookupTime' => [PulseMetrics::FEED_OG_LOOKUP_MS, 'sum'],
            'failures' => [PulseMetrics::FEED_SYNC_FAILED, 'count'],
        ], $this->orderBy), $this->orderBy);

        return $this->renderMetricCard(
            name: 'Feed Sync',
            details: 'per feed, past '.$this->periodForHumans(),
            icon: 'arrows-left-right',
            sections: [[
                'keyLabel' => 'Feed',
                'columns' => [
                    ['label' => 'Runs', 'field' => 'runs'],
                    ['label' => 'Average', 'field' => 'average', 'unit' => 'ms'],
                    ['label' => 'Slowest', 'field' => 'slowest', 'unit' => 'ms'],
                    ['label' => 'Image lookups', 'field' => 'lookups'],
                    ['label' => 'Lookup time', 'field' => 'lookupTime', 'unit' => 'ms'],
                    ['label' => 'Failed', 'field' => 'failures'],
                ],
                'rows' => $rows,
            ]],
            time: $time,
            runAt: $runAt,
            orderOptions: [
                'slowest' => 'slowest',
                'average' => 'average',
                'lookupTime' => 'lookup time',
                'failures' => 'failed',
            ],
        );
    }
}
