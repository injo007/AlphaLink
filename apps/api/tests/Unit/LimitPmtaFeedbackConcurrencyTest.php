<?php

namespace Tests\Unit;

use App\Jobs\Middleware\LimitPmtaFeedbackConcurrency;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LimitPmtaFeedbackConcurrencyTest extends TestCase
{
    public function test_a_worker_slot_is_held_for_the_job_and_released_after_processing(): void
    {
        config(['cache.default' => 'array']);
        $job = new FakeReleasablePmtaJob;

        $result = (new LimitPmtaFeedbackConcurrency)->handle($job, fn (): string => 'processed');

        $this->assertSame('processed', $result);
        $this->assertNull($job->releasedAfter);
        $probe = Cache::lock('pmta-feedback-worker-slot:1', 10);
        $this->assertTrue($probe->get());
        $probe->release();
    }

    public function test_sixth_concurrent_job_is_released_instead_of_opening_a_sixth_sftp_session(): void
    {
        config(['cache.default' => 'array']);
        $locks = [];
        for ($slot = 1; $slot <= 5; $slot++) {
            $lock = Cache::lock('pmta-feedback-worker-slot:'.$slot, 360);
            $this->assertTrue($lock->get());
            $locks[] = $lock;
        }
        $job = new FakeReleasablePmtaJob;
        try {
            $result = (new LimitPmtaFeedbackConcurrency)->handle($job, fn (): string => 'should-not-run');

            $this->assertNull($result);
            $this->assertSame(15, $job->releasedAfter);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }
}

class FakeReleasablePmtaJob
{
    public ?int $releasedAfter = null;

    public function release(int $delay): void
    {
        $this->releasedAfter = $delay;
    }
}
