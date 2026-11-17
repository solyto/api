{{-- Shared view of our Pulse cards (App\Shared\Pulse\Cards). Mirrors the markup of Pulse's own slow-jobs card. --}}
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        :name="$name"
        x-bind:title="`Time: {{ number_format($time, 0) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        :details="$details"
    >
        <x-slot:icon>
            <x-dynamic-component :component="'pulse::icons.'.$icon" />
        </x-slot:icon>
        @if ($orderOptions)
            <x-slot:actions>
                <x-pulse::select
                    wire:model.live="orderBy"
                    :id="$selectId"
                    label="Sort by"
                    :options="$orderOptions"
                    @change="loading = true"
                />
            </x-slot:actions>
        @endif
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @foreach ($sections as $section)
            @if (! empty($section['title']))
                <h3 class="mt-4 first:mt-0 mb-1 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $section['title'] }}</h3>
            @endif

            @if ($section['rows']->isEmpty())
                @if (! empty($section['empty']))
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $section['empty'] }}</p>
                @else
                    <x-pulse::no-results />
                @endif
            @else
                <x-pulse::table>
                    <colgroup>
                        <col width="100%" />
                        @foreach ($section['columns'] as $column)
                            <col width="0%" />
                        @endforeach
                    </colgroup>
                    <x-pulse::thead>
                        <tr>
                            <x-pulse::th>{{ $section['keyLabel'] }}</x-pulse::th>
                            @foreach ($section['columns'] as $column)
                                <x-pulse::th class="text-right whitespace-nowrap">{{ $column['label'] }}</x-pulse::th>
                            @endforeach
                        </tr>
                    </x-pulse::thead>
                    <tbody>
                        @foreach ($section['rows'] as $row)
                            <tr wire:key="{{ $loop->parent->index }}-{{ md5($row['key']) }}-spacer" class="h-2 first:h-0"></tr>
                            <tr wire:key="{{ $loop->parent->index }}-{{ md5($row['key']) }}-row">
                                <x-pulse::td class="max-w-[1px]">
                                    <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $row['key'] }}">
                                        {{ $row['key'] }}
                                    </code>
                                </x-pulse::td>
                                @foreach ($section['columns'] as $column)
                                    <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                        @php($value = $row[$column['field']] ?? 0)
                                        @if (($column['unit'] ?? null) === 'ms')
                                            <strong>{{ number_format((float) $value) ?: '<1' }}</strong> ms
                                        @elseif (($column['unit'] ?? null) === 's')
                                            <strong>{{ number_format((float) $value, (float) $value < 10 ? 1 : 0) }}</strong> s
                                        @else
                                            <strong>{{ number_format((float) $value) }}</strong>
                                        @endif
                                    </x-pulse::td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </x-pulse::table>
            @endif
        @endforeach
    </x-pulse::scroll>
</x-pulse::card>
