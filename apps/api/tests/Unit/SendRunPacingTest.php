<?php

namespace Tests\Unit;

use App\Services\Campaigns\CampaignSendTypeCatalog;
use App\Services\Campaigns\SendRunPacing;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SendRunPacingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_six_hundred_milliseconds_are_not_rounded_up_to_seconds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-27T12:00:00.000Z'));
        $pacing = new SendRunPacing;
        $slept = [];
        $deadline = $pacing->deadlineAfter(600);

        self::assertSame(Carbon::now()->getTimestampMs() + 600, $deadline);
        self::assertSame(0, $pacing->queueDelaySeconds(600));
        self::assertFalse($pacing->deferUntil($deadline, static function (): void {
            self::fail('A sub-second wait must not be released as a whole-second queue delay.');
        }, static function (int $microseconds) use (&$slept): void {
            $slept[] = $microseconds;
        }));
        self::assertSame([600_000], $slept);
    }

    public function test_millisecond_deadlines_keep_the_remainder_after_second_based_queue_delays(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-27T12:00:00.125Z'));
        $pacing = new SendRunPacing;
        $deadline = $pacing->deadlineAfter(1750);
        $released = [];
        $slept = [];

        self::assertSame(Carbon::now()->getTimestampMs() + 1750, $deadline);
        self::assertSame(1, $pacing->queueDelaySeconds(1750));
        Carbon::setTestNow(Carbon::now()->addSecond());
        self::assertFalse($pacing->deferUntil($deadline, static function (int $seconds) use (&$released): void {
            $released[] = $seconds;
        }, static function (int $microseconds) use (&$slept): void {
            $slept[] = $microseconds;
        }));
        self::assertSame([], $released);
        self::assertSame([750_000], $slept);
    }

    public function test_long_deadlines_requeue_without_losing_the_original_due_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-27T12:00:00.000Z'));
        $pacing = new SendRunPacing;
        $released = [];
        $slept = [];
        $deadline = Carbon::now()->getTimestampMs() + 2_350;

        self::assertTrue($pacing->deferUntil($deadline, static function (int $seconds) use (&$released): void {
            $released[] = $seconds;
        }, static function (int $microseconds) use (&$slept): void {
            $slept[] = $microseconds;
        }));
        self::assertSame([2], $released);
        self::assertSame([], $slept);
        self::assertSame(900, $pacing->queueDelaySeconds(2_000_000));
    }

    public function test_legacy_second_deadlines_remain_readable_and_expire_cleanly(): void
    {
        $pacing = new SendRunPacing;
        $deadline = $pacing->deadlineFromOptions(['next_batch_at' => '2026-11-27T12:00:01Z']);

        self::assertSame(Carbon::parse('2026-11-27T12:00:01Z')->getTimestampMs(), $deadline);
        self::assertNull($pacing->deadlineFromOptions(['next_batch_at' => 'not-a-date']));
    }

    public function test_dms_send_type_catalog_is_scoped_to_campaign_warmup_and_drop(): void
    {
        self::assertSame(['delay', 'delayp', 'delayps', 'hs', 'hsp', 'hsps', 'pickup', 'pickups'], CampaignSendTypeCatalog::CAMPAIGN_AND_WARMUP);
        self::assertSame(['delay', 'delayp', 'hs', 'hsp', 'pickup'], CampaignSendTypeCatalog::forCampaignKind('drop'));
        self::assertSame(CampaignSendTypeCatalog::CAMPAIGN_AND_WARMUP, CampaignSendTypeCatalog::forCampaignKind('marketing'));
    }
}
