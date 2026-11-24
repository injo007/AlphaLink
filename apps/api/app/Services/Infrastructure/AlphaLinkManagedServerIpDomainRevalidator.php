<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServerIpDomain;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Rechecks the public DNS, authentication, and installed tracker endpoint for one assignment. */
class AlphaLinkManagedServerIpDomainRevalidator
{
    public function __construct(private readonly AlphaLinkMultiDomainDnsVerifier $dnsVerifier) {}

    public function revalidate(ManagedServerIpDomain $assignment): void
    {
        $assignment->loadMissing(['server', 'ip', 'domain', 'signingIdentity']);

        try {
            $server = $assignment->server;
            $ip = $assignment->ip;
            $domain = $assignment->domain;
            $identity = $assignment->signingIdentity;
            if (! $server?->is_active || ! $server->is_installed
                || ! $ip?->is_active
                || (string) $ip->managed_server_id !== (string) $server->getKey()
                || ! $ip::isPublicAddress($ip->ip)
                || ! $domain?->is_active
                || ! $identity?->is_active
                || (string) $identity->sending_domain_id !== (string) $assignment->sending_domain_id
                || $identity->hostname !== $assignment->hostname
                || ! filled($assignment->dkim_selector)
                || ! filled($identity->public_record)) {
                throw new RuntimeException('The server, IP, sending domain, or DKIM identity is no longer active and valid.');
            }

            $trackingHost = strtolower(rtrim((string) $assignment->tracking_host, '.'));
            if (filter_var($trackingHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new RuntimeException('The tracking hostname is invalid.');
            }

            $this->dnsVerifier->verify([[
                'hostname' => $assignment->hostname,
                'tracking_host' => $trackingHost,
                'ip' => $ip->ip,
                'ehlo_hostname' => $ip->ehlo_hostname ?: $ip->dns,
                'dkim_selector' => $assignment->dkim_selector,
                'dkim_public_record' => $identity->public_record,
            ]], [
                'spf' => true,
                'dkim' => true,
                'dmarc' => true,
                'imap' => (bool) data_get($server->install_parameters, 'imap', false),
            ]);

            // Pin the TLS probe to the already-verified public IP so a second DNS lookup cannot redirect it.
            $address = str_contains((string) $ip->ip, ':') ? '['.$ip->ip.']' : $ip->ip;
            $probe = Http::connectTimeout(5)
                ->timeout(10)
                ->withOptions([
                    'verify' => true,
                    'curl' => [CURLOPT_RESOLVE => [$trackingHost.':443:'.$address]],
                ])
                ->get('https://'.$trackingHost.'/healthz');
            if ($probe->status() !== 200 || trim($probe->body()) !== 'ALPHALINK_TRACKING_READY') {
                throw new RuntimeException('The tracking HTTPS health endpoint did not return ALPHALINK_TRACKING_READY.');
            }

            $assignment->forceFill([
                'dns_verified_at' => now(),
                // DNS records plus the tracker health route do not prove that a received
                // message passed SPF, DKIM and DMARC, so keep sending readiness closed.
                'authentication_verified_at' => null,
                'tracking_https_ready' => true,
            ])->saveOrFail();
        } catch (Throwable $exception) {
            $assignment->forceFill([
                'dns_verified_at' => null,
                'authentication_verified_at' => null,
                'tracking_https_ready' => false,
            ])->saveOrFail();

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Assignment verification failed: '.$exception->getMessage(), previous: $exception);
        }
    }
}
