<?php

namespace App\Shared\Pulse\Cards;

use App\Shared\Pulse\PulseMetrics;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Lazy;

/**
 * E: which app versions are in use, from the version RecordClientVersion
 * stores on each token, plus how often clients were told to update (426).
 * Counts tokens, i.e. signed-in devices, not users.
 */
#[Lazy]
class ClientVersions extends MetricCard
{
    private const int ACTIVE_DAYS = 30;

    public function render(): Renderable
    {
        [[$versions, $rejected], $time, $runAt] = $this->remember(fn () => [
            PersonalAccessToken::query()
                ->where('last_used_at', '>=', now()->subDays(self::ACTIVE_DAYS))
                ->selectRaw('name, client_version, count(*) as tokens')
                ->groupBy('name', 'client_version')
                ->toBase()
                ->get()
                ->map(fn ($row) => [
                    'key' => $this->platform($row->name).' · '.($row->client_version ?? 'unknown'),
                    'tokens' => (int) $row->tokens,
                ])
                ->sortByDesc('tokens')
                ->values(),
            $this->merged(['count' => [PulseMetrics::CLIENT_REJECTED, 'count']], 'count'),
        ], ttl: 60);

        return $this->renderMetricCard(
            name: 'Client Versions',
            details: 'devices active in the last '.self::ACTIVE_DAYS.' days',
            icon: 'computer-desktop',
            sections: [
                [
                    'keyLabel' => 'Platform · version',
                    'columns' => [['label' => 'Devices', 'field' => 'tokens']],
                    'rows' => $versions,
                ],
                [
                    'title' => 'Update required (426), past '.$this->periodForHumans(),
                    'keyLabel' => 'Version',
                    'columns' => [['label' => 'Requests', 'field' => 'count']],
                    'rows' => $rejected,
                    'empty' => 'No client was below the minimum version.',
                ],
            ],
            time: $time,
            runAt: $runAt,
        );
    }

    /** Token names are "auth-token-{web|mobile|desktop}" and "widget-token". */
    private function platform(string $tokenName): string
    {
        return match (true) {
            str_starts_with($tokenName, 'auth-token-') => Str::after($tokenName, 'auth-token-'),
            $tokenName === 'widget-token' => 'widget',
            default => $tokenName,
        };
    }
}
