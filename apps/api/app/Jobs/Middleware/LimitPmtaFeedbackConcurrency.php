<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

/** Keep PMTA SSH/SFTP work within DMS's five-session concurrency envelope. */
class LimitPmtaFeedbackConcurrency
{
    private const MAX_CONCURRENT = 5;

    private const LOCK_SECONDS = 360;

    public function handle(object $job, Closure $next): mixed
    {
        for ($slot = 1; $slot <= self::MAX_CONCURRENT; $slot++) {
            $lock = Cache::lock('pmta-feedback-worker-slot:'.$slot, self::LOCK_SECONDS);
            if (! $lock->get()) {
                continue;
            }

            try {
                return $next($job);
            } finally {
                $lock->release();
            }
        }

        $job->release(15);

        return null;
    }
}
