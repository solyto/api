<?php

namespace App\Shared\Pulse\Cards;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Pulse\Livewire\Card;

/**
 * Base for our Pulse cards. They all render resources/views/pulse/metric-card.blade.php:
 * one or more tables of keys with numeric columns.
 */
abstract class MetricCard extends Card
{
    /**
     * Merges several aggregates into one row per key. The first spec decides
     * which keys exist; the others fill in 0 when a key has no entries.
     *
     * @param  array<string, array{0: string, 1: 'count'|'min'|'max'|'sum'|'avg'}>  $columns  field => [type, aggregate]
     * @return Collection<int, array<string, int|float|string>>
     */
    protected function merged(array $columns, string $orderBy): Collection
    {
        $values = [];
        foreach ($columns as $field => [$type, $aggregate]) {
            $values[$field] = $this->aggregate($type, $aggregate)
                ->mapWithKeys(fn ($row) => [$row->key => $row->{$aggregate}]);
        }

        $primary = array_key_first($columns);

        return $values[$primary]->keys()
            ->map(function (string $key) use ($values) {
                $row = ['key' => $key];
                foreach ($values as $field => $byKey) {
                    $row[$field] = $byKey->get($key) ?? 0;
                }

                return $row;
            })
            ->sortByDesc($orderBy)
            ->values()
            ->take(100);
    }

    /**
     * @param  list<array{title?: string, keyLabel: string, columns: list<array{label: string, field: string, unit?: string}>, rows: Collection<int, array<string, mixed>>, empty?: string}>  $sections
     * @param  array<string, string>|null  $orderOptions  value => label, null for no sort select
     */
    protected function renderMetricCard(
        string $name,
        string $details,
        string $icon,
        array $sections,
        float $time,
        string $runAt,
        ?array $orderOptions = null,
    ): Renderable {
        return View::make('pulse.metric-card', [
            'name' => $name,
            'details' => $details,
            'icon' => $icon,
            'sections' => $sections,
            'time' => $time,
            'runAt' => $runAt,
            'orderOptions' => $orderOptions,
            'selectId' => 'select-'.Str::kebab(class_basename(static::class)),
        ]);
    }
}
