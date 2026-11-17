<x-pulse>
    {{--
        Grouped by the question each section answers, from "do users notice
        problems?" down to details (cols out of 12). Section headings:
        resources/views/components/pulse-section.blade.php. Our own cards live in
        App\Shared\Pulse (PulseMetrics records, Cards display).
    --}}

    <x-pulse-section title="Problems users notice" description="Errors, requests over 1 s, and requests refused by a rate limit" />
    <livewire:pulse.exceptions cols="4" />
    <livewire:pulse.slow-requests cols="4" />
    <livewire:pulse.rate-limits cols="4" />

    <x-pulse-section title="Database and cache" description="Queries over 1 s and cache hit rates" />
    <livewire:pulse.slow-queries cols="6" />
    <livewire:pulse.cache cols="6" />

    <x-pulse-section title="Background work" description="Is the queue keeping up? Throughput, backlog and wait time, slow jobs, and the hourly feed sync" />
    <livewire:pulse.queues cols="6" />
    <livewire:pulse.queue-load cols="6" />
    <livewire:pulse.slow-jobs cols="6" />
    <livewire:pulse.feed-sync cols="6" />

    <x-pulse-section title="Calendar sync" description="Our DAV endpoint (calendar and contact apps) and the remote servers of external calendars" />
    <livewire:pulse.dav-requests cols="6" />
    <livewire:pulse.external-calendars cols="6" />

    <x-pulse-section title="Outgoing requests" description="Calls to other servers over 1 s, and release lookups at Hardcover and Deezer" />
    <livewire:pulse.slow-outgoing-requests cols="6" />
    <livewire:pulse.release-lookups cols="6" />

    <x-pulse-section title="Notifications" description="Sent and failed per channel (mail, Telegram, web push, in-app)" />
    <livewire:pulse.notifications cols="6" />

    <x-pulse-section title="App versions" description="Which app versions are in use, and how often old ones were asked to update" />
    <livewire:pulse.client-versions cols="6" />
</x-pulse>
