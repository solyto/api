<?php

namespace App\Shared\Pulse\Cards;

use App\Shared\Pulse\PulseMetrics;
use Illuminate\Contracts\Support\Renderable;
use Livewire\Attributes\Lazy;

/** F: 429 responses per limiter and route. */
#[Lazy]
class RateLimits extends MetricCard
{
    public function render(): Renderable
    {
        [$rows, $time, $runAt] = $this->remember(fn () => $this->merged([
            'hits' => [PulseMetrics::RATE_LIMITED, 'count'],
        ], 'hits'));

        return $this->renderMetricCard(
            name: 'Rate Limits',
            details: '429 responses, past '.$this->periodForHumans(),
            icon: 'signal-slash',
            sections: [[
                'keyLabel' => 'Limiter · route',
                'columns' => [['label' => 'Hits', 'field' => 'hits']],
                'rows' => $rows,
            ]],
            time: $time,
            runAt: $runAt,
        );
    }
}
