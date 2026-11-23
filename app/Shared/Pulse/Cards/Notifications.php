<?php

namespace App\Shared\Pulse\Cards;

use App\Shared\Pulse\PulseMetrics;
use Illuminate\Contracts\Support\Renderable;
use Livewire\Attributes\Lazy;

#[Lazy]
class Notifications extends MetricCard
{
    public function render(): Renderable
    {
        [$rows, $time, $runAt] = $this->remember(function () {
            $sent = $this->merged(['sent' => [PulseMetrics::NOTIFICATION_SENT, 'count']], 'sent')->keyBy('key');
            $failed = $this->merged(['failed' => [PulseMetrics::NOTIFICATION_FAILED, 'count']], 'failed')->keyBy('key');

            // A key can have only failures, so merge both ways.
            return $sent->keys()->merge($failed->keys())->unique()
                ->map(fn (string $key) => [
                    'key' => $key,
                    'sent' => $sent->get($key)['sent'] ?? 0,
                    'failed' => $failed->get($key)['failed'] ?? 0,
                ])
                ->sortByDesc(fn (array $row) => [$row['failed'], $row['sent']])
                ->values();
        });

        return $this->renderMetricCard(
            name: 'Notifications',
            details: 'per channel and type, past '.$this->periodForHumans(),
            icon: 'information-circle',
            sections: [[
                'keyLabel' => 'Channel · notification',
                'columns' => [
                    ['label' => 'Sent', 'field' => 'sent'],
                    ['label' => 'Failed', 'field' => 'failed'],
                ],
                'rows' => $rows,
            ]],
            time: $time,
            runAt: $runAt,
        );
    }
}
