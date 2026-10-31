<?php

namespace Tests\Unit;

use App\Services\Campaigns\DmsCampaignTrackingTokenBuilder;
use PHPUnit\Framework\TestCase;

class DmsCampaignTrackingTokenBuilderTest extends TestCase
{
    public function test_it_emits_the_strict_standard_base64_token_consumed_by_the_dms_tracker(): void
    {
        $fields = ['campaign', 'send', 'recipient', 'f', 'list', 'isp', 'server', 'ip', 'domain', 'from', 'subject', 'creative'];
        $applicationName = 'AlphaLink';

        $token = (new DmsCampaignTrackingTokenBuilder)->build($fields, $applicationName);
        $decoded = base64_decode($token, true);

        $this->assertIsString($decoded);
        $parts = explode('-', $decoded);
        $this->assertCount(14, $parts);
        $this->assertSame($fields, array_slice($parts, 0, 12));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{9}_[a-f0-9]{8}\z/', $parts[12]);
        $this->assertSame(hash('adler32', $applicationName), substr($parts[12], 10));
        $this->assertSame(hash('adler32', 'campaign_send_recipient'), $parts[13]);
        $this->assertSame(rtrim(base64_encode(implode('-', $parts)), '='), $token);
    }
}
