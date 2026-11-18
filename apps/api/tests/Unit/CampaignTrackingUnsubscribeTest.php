<?php

namespace Tests\Unit;

use App\Services\Campaigns\AlphaLinkCampaignTagRenderer;
use App\Services\Campaigns\CampaignRedirectUrlPolicy;
use Tests\TestCase;

class CampaignTrackingUnsubscribeTest extends TestCase
{
    public function test_campaign_tracking_unsubscribe_url_generates_valid_url_with_action_u(): void
    {
        $policy = new CampaignRedirectUrlPolicy;
        $token = 'synthetic_test_token_12345';
        $unsubUrl = $policy->campaignTrackingUnsubscribeUrl('track.example.com', $token, true);

        $this->assertStringStartsWith('https://track.example.com/', $unsubUrl);
        $this->assertStringEndsWith('?t=1', $unsubUrl);

        // Extract the path
        $path = parse_url($unsubUrl, PHP_URL_PATH);
        $this->assertNotNull($path);

        // Matches the TrackerRequestHandler regex: 10 chars + 'u' + token
        $this->assertMatchesRegularExpression('#^/[A-Za-z0-9]{10}u[A-Za-z0-9_-]+#', $path);
    }

    public function test_tag_renderer_replaces_u_token_and_unsub_tags(): void
    {
        $renderer = new AlphaLinkCampaignTagRenderer;
        $values = [
            'click_url' => 'https://track.example.com/RANDOM1234rTOKEN?t=1',
            'unsub' => 'https://track.example.com/RANDOM1234uTOKEN?t=1',
        ];

        // 1. Direct tag replacement
        $body1 = 'Click <a href="[url]">here</a> or <a href="[unsub]">unsubscribe</a>.';
        $rendered1 = $renderer->render($body1, $values);
        $this->assertStringContainsString('href="https://track.example.com/RANDOM1234rTOKEN?t=1"', $rendered1);
        $this->assertStringContainsString('href="https://track.example.com/RANDOM1234uTOKEN?t=1"', $rendered1);

        // 2. Tag aliases [unsubscribe] and [optout]
        $body2 = 'Opt-out: [unsubscribe] or [optout]';
        $rendered2 = $renderer->render($body2, $values);
        $this->assertSame('Opt-out: https://track.example.com/RANDOM1234uTOKEN?t=1 or https://track.example.com/RANDOM1234uTOKEN?t=1', $rendered2);

        // 3. u[token] pattern normalization in template
        $body3 = 'Link: http://[domain]/[rnd_10]u[token] and http://[domain]/[rnd_10]r[token]';
        $rendered3 = $renderer->render($body3, $values);
        $this->assertSame('Link: https://track.example.com/RANDOM1234uTOKEN?t=1 and https://track.example.com/RANDOM1234rTOKEN?t=1', $rendered3);
    }
}
