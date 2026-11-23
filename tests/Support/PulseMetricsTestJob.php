<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PulseMetricsTestJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void {}
}
