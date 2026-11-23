<?php

namespace App\Shared\Pulse\Cards;

use App\Shared\Pulse\PulseMetrics;
use Illuminate\Contracts\Support\Renderable;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

#[Lazy]
class ExternalCalendars extends MetricCard
{
    #[Url(as: 'external-calendars')]
    public string $orderBy = 'slowest';

    public function render(): Renderable
    {
        [[$calls, $failures], $time, $runAt] = $this->remember(fn () => [
            $this->merged([
                'calls' => [PulseMetrics::EXTERNAL_CALENDAR_CALL, 'count'],
                'average' => [PulseMetrics::EXTERNAL_CALENDAR_CALL, 'avg'],
                'slowest' => [PulseMetrics::EXTERNAL_CALENDAR_CALL, 'max'],
            ], $this->orderBy),
            $this->merged([
                'count' => [PulseMetrics::EXTERNAL_CALENDAR_FAILURE, 'count'],
            ], 'count'),
        ], $this->orderBy);

        return $this->renderMetricCard(
            name: 'External Calendars',
            details: 'remote CalDAV calls per host, past '.$this->periodForHumans(),
            icon: 'cloud-arrow-up',
            sections: [
                [
                    'keyLabel' => 'Host',
                    'columns' => [
                        ['label' => 'Calls', 'field' => 'calls'],
                        ['label' => 'Average', 'field' => 'average', 'unit' => 'ms'],
                        ['label' => 'Slowest', 'field' => 'slowest', 'unit' => 'ms'],
                    ],
                    'rows' => $calls,
                ],
                [
                    'title' => 'Failures',
                    'keyLabel' => 'Host · reason',
                    'columns' => [['label' => 'Count', 'field' => 'count']],
                    'rows' => $failures,
                    'empty' => 'No failed calls.',
                ],
            ],
            time: $time,
            runAt: $runAt,
            orderOptions: ['slowest' => 'slowest', 'average' => 'average', 'calls' => 'calls'],
        );
    }
}
