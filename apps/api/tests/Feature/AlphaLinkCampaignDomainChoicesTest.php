<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpDomain;
use App\Models\SendingDomain;
use App\Models\SendingDomainDkimIdentity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\AlphaLinkCampaignSendCatalog;
use App\Services\Campaigns\PmtaCampaignTestTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Tests\TestCase;

class AlphaLinkCampaignDomainChoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_choices_use_assignment_readiness_without_sender_ownership_txt_verification(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Domain route test', 'slug' => 'domain-route-test', 'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner']);
        $workspace->infrastructureSettings()->create(['pmta_port' => 2525]);
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'MAIL SERVER', 'provider' => 'Synthetic',
            'ip_address' => '8.8.8.8', 'ssh_port' => 22, 'ssh_username' => 'root',
            'state' => 'verified', 'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $user->getKey(),
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'example.test',
            'state' => 'pending', 'status' => 'used',
            'verification_token' => 'synthetic', 'is_our' => true, 'is_active' => true,
            'created_by' => $user->getKey(),
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(), 'ip' => '8.8.8.8', 'type' => 'ipv4',
            'dns' => 'mail.example.test', 'is_active' => true,
        ]);
        $identity = SendingDomainDkimIdentity::factory()->forDomain($domain)
            ->state(['hostname' => 'mail.example.test'])->create();
        ManagedServerIpDomain::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(), 'sending_domain_id' => $domain->getKey(),
            'hostname' => 'mail.example.test', 'tracking_host' => 'click.mail.example.test',
            'dkim_selector' => $identity->selector, 'sending_domain_dkim_identity_id' => $identity->getKey(),
            'is_primary' => true, 'is_active' => true, 'tracking_https_ready' => true,
            'dns_verified_at' => now(), 'authentication_verified_at' => now(),
        ]);
        $pendingIdentity = SendingDomainDkimIdentity::factory()->forDomain($domain)
            ->state(['hostname' => 'offers.example.test'])->create();
        /*
         * This assignment has its PMTA signing identity, but its public DNS
         * and tracking HTTPS proof are pending. It must remain send-selectable.
         */
        ManagedServerIpDomain::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(), 'sending_domain_id' => $domain->getKey(),
            'hostname' => 'offers.example.test', 'tracking_host' => 'click.offers.example.test',
            'dkim_selector' => $pendingIdentity->selector,
            'sending_domain_dkim_identity_id' => $pendingIdentity->getKey(),
            'is_primary' => false, 'is_active' => true, 'tracking_https_ready' => false,
        ]);

        $choices = app(AlphaLinkCampaignSendCatalog::class)->domainChoices(
            $workspace, $user, 'owner', [(string) $server->getKey()],
        );

        $this->assertCount(2, $choices);
        $this->assertSame('mail.example.test', $choices[0]['domain']);
        $this->assertTrue($choices[0]['is_ready']);
        $this->assertSame(1, ManagedServerIpDomain::readyForSending()->where('managed_server_id', $server->getKey())->count());
        $this->assertSame('8.8.8.8', $choices[0]['ip']);
        $this->assertSame('mta-8.8.8.8', $choices[0]['pmta_name']);
        $this->assertSame('click.mail.example.test', $choices[0]['tracking_host']);
        $this->assertSame('offers.example.test', $choices[1]['domain']);
        $this->assertTrue($choices[1]['is_ready']);
        $this->assertTrue($choices[1]['verification_pending']);
        $this->assertFalse($choices[1]['tracking_https_ready']);
        $this->assertFalse($choices[1]['dns_verified']);
        $this->assertFalse($choices[1]['authentication_verified']);

        $routing = app(AlphaLinkCampaignSendCatalog::class)->validateRevisionRouting(
            $workspace, $user, 'owner', 'domain', 'ipv4', [(string) $server->getKey()], [$choices[0]['id']],
        );
        $this->assertSame('domain', $routing['route_option']);
        $this->assertSame([$choices[0]['id']], $routing['ip_ids']);
        $pendingRouting = app(AlphaLinkCampaignSendCatalog::class)->validateRevisionRouting(
            $workspace, $user, 'owner', 'domain', 'ipv4', [(string) $server->getKey()], [$choices[1]['id']],
        );
        $this->assertSame([$choices[1]['id']], $pendingRouting['ip_ids']);

        $messages = [];
        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('send')->once()->withArgs(function ($message) use (&$messages): bool {
            $messages[] = $message->toString();

            return true;
        });
        $transport->shouldReceive('stop')->once();
        $factory = Mockery::mock(PmtaCampaignTestTransport::class);
        $factory->shouldReceive('forServer')->once()->andReturn($transport);
        $this->app->instance(PmtaCampaignTestTransport::class, $factory);

        $this->actingAs($user)->postJson(route('console.campaigns.manual-test', $workspace->getKey()), [
            'idempotency_key' => (string) Str::uuid(),
            'email_test_addresses' => 'recipient@example.test',
            'header' => "To: [to]\nFrom: sender@[domain]\nSubject: Assignment test",
            'body' => 'Domain: [domain]',
            'return_path' => 'bounce@[domain]',
            'send_setup' => [
                'server_ids' => [(string) $server->getKey()],
                'ip_ids' => [$choices[1]['id']],
                'route_option' => 'domain', 'ip_type' => 'ipv4', 'send_type' => 'delay',
            ],
        ])->assertOk()->assertJsonPath('outcome', 'accepted_by_transport');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('From: sender@offers.example.test', $messages[0]);
        $this->assertStringContainsString('X-virtual-MTA: mta-8.8.8.8', $messages[0]);

        $this->actingAs($user)->postJson(route('console.campaigns.manual-test', $workspace->getKey()), [
            'idempotency_key' => (string) Str::uuid(),
            'email_test_addresses' => 'recipient@example.test',
            'header' => "To: [to]\nFrom: sender@wrong.example.test\nSubject: Assignment test",
            'body' => 'Domain: [domain]',
            'send_setup' => [
                'server_ids' => [(string) $server->getKey()], 'ip_ids' => [$choices[0]['id']],
                'route_option' => 'domain', 'ip_type' => 'ipv4', 'send_type' => 'delay',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('header');
        $this->assertCount(1, $messages);
    }
}
