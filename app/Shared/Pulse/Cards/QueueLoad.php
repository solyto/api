<?php

namespace App\Shared\Pulse\Cards;

use App\Shared\Pulse\PulseMetrics;
use Illuminate\Contracts\Support\Renderable;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * H: is the queue keeping up? Queue length (sampled every minute by the
 * scheduler) and how long jobs wait before a worker starts them.
 */
#[Lazy]
class QueueLoad extends MetricCard
{
    #[Url(as: 'queue-load')]
    public string $orderBy = 'longest';

    public function render(): Renderable
    {
        [[$sizes, $waits], $time, $runAt] = $this->remember(function () {
            $now = $this->values(PulseMetrics::QUEUE_SIZE)->map(fn ($value) => (int) $value->value);

            $sizes = $this->merged([
                'average' => [PulseMetrics::QUEUE_SIZE, 'avg'],
                'largest' => [PulseMetrics::QUEUE_SIZE, 'max'],
            ], 'largest')->map(fn (array $row) => ['now' => $now->get($row['key'], 0)] + $row);

            $waits = $this->merged([
                'jobs' => [PulseMetrics::QUEUE_WAIT, 'count'],
                'average' => [PulseMetrics::QUEUE_WAIT, 'avg'],
                'longest' => [PulseMetrics::QUEUE_WAIT, 'max'],
            ], $this->orderBy);

            return [$sizes, $waits];
        }, $this->orderBy);

        return $this->renderMetricCard(
            name: 'Queue Load',
            details: 'past '.$this->periodForHumans(),
            icon: 'queue-list',
            sections: [
                [
                    'title' => 'Jobs in the queue (sampled every minute)',
                    'keyLabel' => 'Queue',
                    'columns' => [
                        ['label' => 'Now', 'field' => 'now'],
                        ['label' => 'Average', 'field' => 'average'],
                        ['label' => 'Largest', 'field' => 'largest'],
                    ],
                    'rows' => $sizes,
                    'empty' => 'No samples yet. The scheduler records them every minute.',
                ],
                [
                    'title' => 'Wait before a worker starts the job',
                    'keyLabel' => 'Queue · job',
                    'columns' => [
                        ['label' => 'Jobs', 'field' => 'jobs'],
                        ['label' => 'Average', 'field' => 'average', 'unit' => 's'],
                        ['label' => 'Longest', 'field' => 'longest', 'unit' => 's'],
                    ],
                    'rows' => $waits,
                ],
            ],
            time: $time,
            runAt: $runAt,
            orderOptions: ['longest' => 'longest wait', 'average' => 'average wait', 'jobs' => 'jobs'],
        );
    }
}
