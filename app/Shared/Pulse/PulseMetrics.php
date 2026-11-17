<?php

namespace App\Shared\Pulse;

use Laravel\Pulse\Facades\Pulse;

/**
 * Custom Pulse metrics, shown by the cards in App\Shared\Pulse\Cards.
 *
 * Every method runs inside Pulse::rescue(): a failing measurement must never
 * break the request, job or notification it measures. Errors end up in the
 * log (see PulseMetricsServiceProvider).
 *
 * Keys hold feed URLs, server hosts, client names and versions, never user
 * names or credentials.
 */
final class PulseMetrics
{
    public const string FEED_SYNC = 'feed_sync';
    public const string FEED_SYNC_FAILED = 'feed_sync_failed';
    public const string FEED_OG_LOOKUPS = 'feed_og_lookups';
    public const string FEED_OG_LOOKUP_MS = 'feed_og_lookup_ms';
    public const string EXTERNAL_CALENDAR_CALL = 'external_calendar_call';
    public const string EXTERNAL_CALENDAR_FAILURE = 'external_calendar_failure';
    public const string DAV_REQUEST = 'dav_request';
    public const string NOTIFICATION_SENT = 'notification_sent';
    public const string NOTIFICATION_FAILED = 'notification_failed';
    public const string RATE_LIMITED = 'rate_limited';
    public const string CLIENT_REJECTED = 'client_rejected';
    public const string RELEASE_LOOKUP = 'release_lookup';
    public const string QUEUE_WAIT = 'queue_wait';
    public const string QUEUE_SIZE = 'queue_size';

    /** Payload key with the time a job may start (push time plus delay), set by PulseMetricsServiceProvider. */
    public const string PAYLOAD_AVAILABLE_AT = 'pulseAvailableAt';

    /**
     * User-Agent fragments of the DAV clients we want to tell apart, first
     * match wins. Anything else counts as "Other".
     */
    private const array DAV_CLIENTS = [
        'DAVx5' => 'DAVx5',
        'Thunderbird' => 'Thunderbird',
        'Evolution' => 'Evolution',
        'CalDavSynchronizer' => 'Outlook (CalDav Synchronizer)',
        'iOS' => 'iOS',
        'iPhone' => 'iOS',
        'iPad' => 'iOS',
        'dataaccessd' => 'iOS',
        'macOS' => 'macOS',
        'Mac OS X' => 'macOS',
        'CalendarAgent' => 'macOS',
        'AddressBookCore' => 'macOS',
        'Android' => 'Android',
        'Windows' => 'Windows',
    ];

    public static function startTimer(): int
    {
        return hrtime(true);
    }

    public static function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /** A: one SyncFeed run. */
    public static function feedSync(string $feedUrl, int $ms, int $ogLookups, int $ogLookupMs, bool $failed): void
    {
        Pulse::rescue(function () use ($feedUrl, $ms, $ogLookups, $ogLookupMs, $failed) {
            Pulse::record(self::FEED_SYNC, $feedUrl, $ms)->max()->avg()->count();
            Pulse::record(self::FEED_OG_LOOKUPS, $feedUrl, $ogLookups)->sum();
            Pulse::record(self::FEED_OG_LOOKUP_MS, $feedUrl, $ogLookupMs)->sum();

            if ($failed) {
                Pulse::record(self::FEED_SYNC_FAILED, $feedUrl)->count();
            }
        });
    }

    /** B: one remote CalDAV request. $failure is null on success. */
    public static function externalCalendarCall(string $url, int $ms, ?string $failure): void
    {
        Pulse::rescue(function () use ($url, $ms, $failure) {
            $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';

            Pulse::record(self::EXTERNAL_CALENDAR_CALL, $host, $ms)->max()->avg()->count();

            if ($failure !== null) {
                Pulse::record(self::EXTERNAL_CALENDAR_FAILURE, $host.' · '.$failure)->count();
            }
        });
    }

    /** C: one request on the DAV endpoint. */
    public static function davRequest(string $method, ?string $userAgent, int $ms): void
    {
        Pulse::rescue(function () use ($method, $userAgent, $ms) {
            Pulse::record(self::DAV_REQUEST, strtoupper($method).' · '.self::davClient($userAgent), $ms)->max()->avg()->count();
        });
    }

    public static function davClient(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'Unknown';
        }

        foreach (self::DAV_CLIENTS as $fragment => $client) {
            if (stripos($userAgent, $fragment) !== false) {
                return $client;
            }
        }

        return 'Other';
    }

    /** D: one notification on one channel. */
    public static function notification(string $channel, string $notification, bool $failed): void
    {
        Pulse::rescue(function () use ($channel, $notification, $failed) {
            $key = self::shortName($channel, 'Channel').' · '.self::shortName($notification, 'Notification');

            Pulse::record($failed ? self::NOTIFICATION_FAILED : self::NOTIFICATION_SENT, $key)->count();
        });
    }

    /** E: a request refused with 426 because the client is below min_client_version. */
    public static function clientRejected(?string $version): void
    {
        Pulse::rescue(function () use ($version) {
            Pulse::record(self::CLIENT_REJECTED, $version ?? 'unknown')->count();
        });
    }

    /** F: a request answered with 429. */
    public static function rateLimited(string $key): void
    {
        Pulse::rescue(function () use ($key) {
            Pulse::record(self::RATE_LIMITED, $key)->count();
        });
    }

    /**
     * G: one release lookup for one user. $source is "request" (a cache miss
     * the user waits for) or "job" (the scheduled refresh).
     */
    public static function releaseLookup(string $library, string $source, int $ms): void
    {
        Pulse::rescue(function () use ($library, $source, $ms) {
            Pulse::record(self::RELEASE_LOOKUP, $library.' · '.$source, $ms)->max()->avg()->count();
        });
    }

    /**
     * Runs a release lookup and records how long it took; returns its result.
     *
     * @template T
     *
     * @param  callable(): T  $lookup
     * @return T
     */
    public static function timedReleaseLookup(string $library, string $source, callable $lookup): mixed
    {
        $startedAt = self::startTimer();

        try {
            return $lookup();
        } finally {
            self::releaseLookup($library, $source, self::elapsedMs($startedAt));
        }
    }

    /**
     * H1: seconds a job waited between becoming available (pushed, plus its
     * delay) and a worker picking it up. Retries are left out: they keep the
     * original push time and would look like long waits.
     */
    public static function queueWait(string $queue, string $job, int $seconds): void
    {
        Pulse::rescue(function () use ($queue, $job, $seconds) {
            Pulse::record(self::QUEUE_WAIT, $queue.' · '.class_basename($job), max(0, $seconds))->max()->avg()->count();
        });
    }

    /** H2: the number of jobs in a queue (waiting, delayed and reserved), sampled every minute. */
    public static function queueSize(string $queue, int $size): void
    {
        Pulse::rescue(function () use ($queue, $size) {
            Pulse::record(self::QUEUE_SIZE, $queue, $size)->max()->avg();
            Pulse::set(self::QUEUE_SIZE, $queue, (string) $size);
        });
    }

    /** When a job with this $delay (the Queueable property) may start, as a Unix timestamp. */
    public static function availableAt(mixed $delay): int
    {
        $now = time();

        return match (true) {
            $delay instanceof \DateTimeInterface => $delay->getTimestamp(),
            $delay instanceof \DateInterval => (new \DateTimeImmutable('@'.$now))->add($delay)->getTimestamp(),
            is_int($delay) => $now + $delay,
            default => $now,
        };
    }

    /** "NotificationChannels\WebPush\WebPushChannel" → "WebPush", "mail" stays "mail". */
    private static function shortName(string $class, string $suffix): string
    {
        $name = class_basename($class);

        return str_ends_with($name, $suffix) && $name !== $suffix
            ? substr($name, 0, -strlen($suffix))
            : $name;
    }
}
