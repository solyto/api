<?php

namespace App\Shared\Pulse;

use Illuminate\Support\Facades\Queue;

// Run by the scheduler every minute for the Queue Load card.
class RecordQueueSize
{
    public function __invoke(): void
    {
        $connection = config('queue.default');
        $queue = config("queue.connections.{$connection}.queue", 'default');

        PulseMetrics::queueSize($queue, Queue::connection($connection)->size($queue));
    }
}
