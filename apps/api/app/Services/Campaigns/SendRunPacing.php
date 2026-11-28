<?php

namespace App\Services\Campaigns;

use Closure;
use Illuminate\Support\Carbon;
use Throwable;

/** Keeps UI pacing values in milliseconds while accommodating second-based queue delays. */
final class SendRunPacing
{
    public const MAX_QUEUE_DELAY_SECONDS = 900;

    public function deadlineAfter(int $delayMilliseconds): ?int
    {
        return $delayMilliseconds > 0
            ? Carbon::now()->getTimestampMs() + $delayMilliseconds
            : null;
    }

    public function queueDelaySeconds(int $delayMilliseconds): int
    {
        return min(self::MAX_QUEUE_DELAY_SECONDS, max(0, intdiv(max(0, $delayMilliseconds), 1000)));
    }

    /**
     * Requeue longer waits and sleep only the final sub-second remainder.
     * Returns true when the current job was released back to its queue.
     *
     * @param  Closure(int): mixed  $release
     * @param  Closure(int): mixed  $sleepMicroseconds
     */
    public function deferUntil(?int $deadlineMilliseconds, Closure $release, Closure $sleepMicroseconds): bool
    {
        if ($deadlineMilliseconds === null) {
            return false;
        }

        $remaining = max(0, $deadlineMilliseconds - Carbon::now()->getTimestampMs());
        if ($remaining === 0) {
            return false;
        }

        if ($remaining >= 1000) {
            $release(min(self::MAX_QUEUE_DELAY_SECONDS, max(1, intdiv($remaining, 1000))));

            return true;
        }

        $sleepMicroseconds($remaining * 1000);

        return false;
    }

    /** @param array<string, mixed> $options */
    public function deadlineFromOptions(array $options): ?int
    {
        $deadline = $options['next_batch_at_ms'] ?? null;
        if (is_numeric($deadline)) {
            return (int) $deadline;
        }

        // Read old queued runs written before millisecond deadlines were introduced.
        $legacyDeadline = $options['next_batch_at'] ?? null;
        if (! is_string($legacyDeadline) || trim($legacyDeadline) === '') {
            return null;
        }

        try {
            return Carbon::parse($legacyDeadline)->getTimestampMs();
        } catch (Throwable) {
            return null;
        }
    }

    public function iso8601Deadline(int $deadlineMilliseconds): string
    {
        return Carbon::createFromTimestampMs($deadlineMilliseconds, 'UTC')->toISOString();
    }
}
