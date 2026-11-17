<?php

namespace App\Shared\Pulse\Cards;

use App\Shared\Pulse\PulseMetrics;
use Illuminate\Contracts\Support\Renderable;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/** C: DAV requests per method and client. */
#[Lazy]
class DavRequests extends MetricCard
{
    #[Url(as: 'dav-requests')]
    public string $orderBy = 'requests';

    public function render(): Renderable
    {
        [$rows, $time, $runAt] = $this->remember(fn () => $this->merged([
            'requests' => [PulseMetrics::DAV_REQUEST, 'count'],
            'average' => [PulseMetrics::DAV_REQUEST, 'avg'],
            'slowest' => [PulseMetrics::DAV_REQUEST, 'max'],
        ], $this->orderBy), $this->orderBy);

        return $this->renderMetricCard(
            name: 'DAV Requests',
            details: 'per method and client, past '.$this->periodForHumans(),
            icon: 'server',
            sections: [[
                'keyLabel' => 'Method · client',
                'columns' => [
                    ['label' => 'Requests', 'field' => 'requests'],
                    ['label' => 'Average', 'field' => 'average', 'unit' => 'ms'],
                    ['label' => 'Slowest', 'field' => 'slowest', 'unit' => 'ms'],
                ],
                'rows' => $rows,
            ]],
            time: $time,
            runAt: $runAt,
            orderOptions: ['requests' => 'requests', 'average' => 'average', 'slowest' => 'slowest'],
        );
    }
}
