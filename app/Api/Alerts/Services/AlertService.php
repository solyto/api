<?php

namespace App\Api\Alerts\Services;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Enums\AlertSource;
use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Users\Models\User;
use App\Shared\Exceptions\LimitReachedException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AlertService
{
    public const int MAX_ALERTS = 50;

    public const array SUGGESTIONS = [
        'no_sport_week' => ['tracker' => 'sports', 'rule' => AlertRule::NoEntryDays, 'threshold' => null, 'days' => 7],
        'mood_trending_down' => ['tracker' => 'mood', 'rule' => AlertRule::AverageBelow, 'threshold' => 3, 'days' => 7],
        'sleep_trending_down' => ['tracker' => 'sleep', 'rule' => AlertRule::AverageBelow, 'threshold' => 3, 'days' => 7],
        'drinking_more' => ['tracker' => 'alcohol', 'rule' => AlertRule::AverageBelow, 'threshold' => 4, 'days' => 7],
    ];

    private const array RELATIONS = ['checkInTracker', 'goal.checkInTracker', 'user.settings'];

    public function __construct(private readonly AlertEvaluator $evaluator) {}

    /** @return Collection<int, Alert> */
    public function list(User $user): Collection
    {
        return Alert::forUser($user->id)
            ->with(self::RELATIONS)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int, Alert> $alerts
     * @return array<int, array<string, mixed>>
     */
    public function states(User $user, Collection $alerts): array
    {
        if ($alerts->isEmpty()) {
            return [];
        }

        $states = $this->evaluator->evaluate($alerts, [$user->id => $user->today()]);

        // Which goal period was judged only matters for sending notifications; the API's state doesn't show it.
        return array_map(fn (array $state) => Arr::except($state, ['period_start', 'period_finished']), $states);
    }

    public function create(User $user, array $data): Alert
    {
        $this->guardLimit($user);

        $tracker = CheckInTracker::forUser($user->id)->findOrFail($data['check_in_tracker_id']);
        $rule = AlertRule::from($data['rule']);
        $this->validateRule($tracker, $rule, $data['threshold'] ?? null);

        $alert = Alert::create([
            'user_id' => $user->id,
            'source' => AlertSource::CheckInTracker,
            'check_in_tracker_id' => $tracker->id,
            'rule' => $rule,
            'threshold' => $rule->isAverage() ? $data['threshold'] : null,
            'days' => $data['days'],
        ]);

        return $this->load($alert);
    }

    public function update(Alert $alert, array $data): Alert
    {
        if ($alert->rule === AlertRule::GoalReached) {
            $alert->update(Arr::only($data, ['enabled']));

            return $this->load($alert);
        }

        $rule = AlertRule::from($data['rule'] ?? $alert->rule->value);
        $threshold = array_key_exists('threshold', $data) ? $data['threshold'] : $alert->threshold;
        $this->validateRule($alert->checkInTracker, $rule, $threshold);

        // A changed rule starts fresh: it may alert again right away if it already holds.
        $ruleUnchanged = $rule === $alert->rule && $this->sameNumber($threshold, $alert->threshold);

        $alert->update([
            ...Arr::only($data, ['days', 'enabled']),
            'rule' => $rule,
            'threshold' => $rule->isAverage() ? $threshold : null,
            'is_triggered' => $ruleUnchanged ? $alert->is_triggered : false,
        ]);

        return $this->load($alert);
    }

    public function delete(Alert $alert): void
    {
        $alert->delete();
    }

    public function suggestions(User $user): array
    {
        $taken = Alert::forUser($user->id)->whereNotNull('suggestion')->pluck('suggestion')->all();
        $trackers = CheckInTracker::forUser($user->id)->whereNotNull('key')->where('enabled', true)->get()->keyBy('key');

        $available = [];
        foreach (self::SUGGESTIONS as $key => $suggestion) {
            if (in_array($key, $taken, true) || ! $trackers->has($suggestion['tracker'])) {
                continue;
            }

            $available[] = [
                'key' => $key,
                'check_in_tracker_id' => $trackers[$suggestion['tracker']]->id,
                'rule' => $suggestion['rule']->value,
                'threshold' => $suggestion['threshold'] === null ? null : (float) $suggestion['threshold'],
                'days' => $suggestion['days'],
            ];
        }

        return $available;
    }

    public function enableSuggestion(User $user, string $key): Alert
    {
        $suggestion = collect($this->suggestions($user))->firstWhere('key', $key);

        if ($suggestion === null) {
            throw ValidationException::withMessages(['suggestion' => 'This suggestion is not available.']);
        }

        $this->guardLimit($user);

        $alert = Alert::create([
            'user_id' => $user->id,
            'source' => AlertSource::CheckInTracker,
            'check_in_tracker_id' => $suggestion['check_in_tracker_id'],
            'rule' => $suggestion['rule'],
            'threshold' => $suggestion['threshold'],
            'days' => $suggestion['days'],
            'suggestion' => $key,
        ]);

        return $this->load($alert);
    }

    private function load(Alert $alert): Alert
    {
        return $alert->fresh(self::RELATIONS);
    }

    private function sameNumber(int|float|null $a, int|float|null $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (float) $a === (float) $b;
    }

    private function guardLimit(User $user): void
    {
        if (Alert::forUser($user->id)->count() >= self::MAX_ALERTS) {
            throw new LimitReachedException('You have reached the maximum number of alerts.');
        }
    }

    private function validateRule(CheckInTracker $tracker, AlertRule $rule, int|float|null $threshold): void
    {
        if (! in_array($rule, AlertRule::forTrackerType($tracker->type), true)) {
            throw ValidationException::withMessages(['rule' => 'This rule is not available for this tracker.']);
        }

        if ($rule->isAverage() && $threshold === null) {
            throw ValidationException::withMessages(['threshold' => 'An average rule needs a threshold.']);
        }
    }
}
