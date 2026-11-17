<?php

namespace App\Shared\Providers;

use App\Shared\Pulse\Cards\ClientVersions;
use App\Shared\Pulse\Cards\DavRequests;
use App\Shared\Pulse\Cards\ExternalCalendars;
use App\Shared\Pulse\Cards\FeedSync;
use App\Shared\Pulse\Cards\Notifications;
use App\Shared\Pulse\Cards\QueueLoad;
use App\Shared\Pulse\Cards\RateLimits;
use App\Shared\Pulse\Cards\ReleaseLookups;
use App\Shared\Pulse\PulseMetrics;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

/**
 * Our own Pulse metrics: the cards on the dashboard
 * (resources/views/vendor/pulse/dashboard.blade.php) and the listeners that
 * record what no single code path can (notifications).
 */
class PulseMetricsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Pulse swallows errors in Pulse::rescue() by default; log them, so a broken
        // measurement shows up without ever breaking the request it measures.
        Pulse::handleExceptionsUsing(function (\Throwable $e) {
            Log::warning('Pulse recording failed', ['error' => $e->getMessage()]);
        });

        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            PulseMetrics::notification($event->channel, $event->notification::class, false);
        });

        Event::listen(NotificationFailed::class, function (NotificationFailed $event) {
            PulseMetrics::notification($event->channel, $event->notification::class, true);
        });

        // H1: the payload's createdAt ignores a job's delay, so store when it may actually start.
        Queue::createPayloadUsing(function (?string $connection, ?string $queue, array $payload): array {
            return Pulse::rescue(function () use ($payload) {
                $command = $payload['data']['command'] ?? null;

                return [PulseMetrics::PAYLOAD_AVAILABLE_AT => PulseMetrics::availableAt(is_object($command) ? ($command->delay ?? null) : null)];
            }) ?? [];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            Pulse::rescue(function () use ($event) {
                if ($event->job->attempts() > 1) {
                    return;
                }

                $payload = $event->job->payload();
                $availableAt = $payload[PulseMetrics::PAYLOAD_AVAILABLE_AT] ?? $payload['createdAt'] ?? null;

                if (is_int($availableAt)) {
                    PulseMetrics::queueWait($event->job->getQueue() ?? 'default', $event->job->resolveName(), time() - $availableAt);
                }
            });
        });

        Livewire::component('pulse.feed-sync', FeedSync::class);
        Livewire::component('pulse.external-calendars', ExternalCalendars::class);
        Livewire::component('pulse.dav-requests', DavRequests::class);
        Livewire::component('pulse.notifications', Notifications::class);
        Livewire::component('pulse.client-versions', ClientVersions::class);
        Livewire::component('pulse.rate-limits', RateLimits::class);
        Livewire::component('pulse.release-lookups', ReleaseLookups::class);
        Livewire::component('pulse.queue-load', QueueLoad::class);
    }
}
