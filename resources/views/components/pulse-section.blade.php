{{--
    Full-width section heading between cards on the Pulse dashboard. Only uses
    classes that exist in Pulse's prebuilt CSS (vendor/laravel/pulse/dist/pulse.css),
    since the dashboard doesn't load our own stylesheet.
--}}
@props(['title', 'description' => null])

<div class="default:col-span-full pt-6 pb-1 border-b border-gray-200 dark:border-gray-900">
    <h2 class="text-lg font-medium text-gray-700 dark:text-gray-300">{{ $title }}</h2>
    @if ($description)
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
</div>
