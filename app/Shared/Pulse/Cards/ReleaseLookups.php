<?php

namespace App\Shared\Pulse\Cards;

use App\Shared\Pulse\PulseMetrics;
use Illuminate\Contracts\Support\Renderable;
use Livewire\Attributes\Lazy;

/** G: book and music release lookups, from a cache miss in a request or from the scheduled job. */
#[Lazy]
class ReleaseLookups extends MetricCard
{
    public function render(): Renderable
    {
        [$rows, $time, $runAt] = $this->remember(fn () => $this->merged([
            'lookups' => [PulseMetrics::RELEASE_LOOKUP, 'count'],
            'average' => [PulseMetrics::RELEASE_LOOKUP, 'avg'],
            'slowest' => [PulseMetrics::RELEASE_LOOKUP, 'max'],
        ], 'slowest'));

        return $this->renderMetricCard(
            name: 'Release Lookups',
            details: 'per user and library; "request" means the user waited, past '.$this->periodForHumans(),
            icon: 'rocket-launch',
            sections: [[
                'keyLabel' => 'Library · source',
                'columns' => [
                    ['label' => 'Lookups', 'field' => 'lookups'],
                    ['label' => 'Average', 'field' => 'average', 'unit' => 'ms'],
                    ['label' => 'Slowest', 'field' => 'slowest', 'unit' => 'ms'],
                ],
                'rows' => $rows,
            ]],
            time: $time,
            runAt: $runAt,
        );
    }
}
