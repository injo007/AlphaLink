<?php

namespace Tests\Unit;

use App\Services\Campaigns\DmsCampaignTrackingTokenBuilder;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class DmsCampaignTrackingTokenBuilderTest extends TestCase
{
    public function test_it_emits_a_url_safe_base64_token_consumed_by_the_installed_dms_tracker(): void
    {
        $fields = ['campaign', 'send', 'recipient', 'f', 'list', 'isp', 'server', 'ip', 'domain', 'from', 'subject', 'creative'];
        $applicationName = 'AlphaLink';

        $token = (new DmsCampaignTrackingTokenBuilder)->build($fields, $applicationName);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $token);
        $this->assertStringNotContainsString('/', $token);
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);

        $this->assertIsString($decoded);
        $parts = explode('-', $decoded);
        $this->assertCount(14, $parts);
        $this->assertSame($fields, array_slice($parts, 0, 12));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{9}_[a-f0-9]{8}\z/', $parts[12]);
        $this->assertSame(hash('adler32', $applicationName), substr($parts[12], 10));
        $this->assertSame(hash('adler32', 'campaign_send_recipient'), $parts[13]);
        $this->assertSame(rtrim(strtr(base64_encode(implode('-', $parts)), '+/', '-_'), '='), $token);
    }

    public function test_it_splits_long_uuid_tokens_into_apache_safe_path_segments(): void
    {
        $fields = array_fill(0, 12, str_replace('-', '', (string) Str::uuid()));
        $fields[3] = 'f';
        $token = (new DmsCampaignTrackingTokenBuilder)->build($fields, 'AlphaLink');
        $segments = explode('/', $token);
        $joined = implode('', $segments);

        $this->assertGreaterThan(1, count($segments));
        $this->assertLessThanOrEqual(160, max(array_map('strlen', $segments)));
        $decoded = base64_decode(strtr($joined, '-_', '+/'), true);
        $this->assertIsString($decoded);
        $this->assertCount(14, explode('-', $decoded));
    }
}
