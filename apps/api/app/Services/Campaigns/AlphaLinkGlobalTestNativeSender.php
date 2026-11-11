<?php

namespace App\Services\Campaigns;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignNegative;
use App\Services\Infrastructure\AlphaLinkBulkInstallVmtaPlanner;
use App\Services\PmtaRemoteConfiguration;
use App\Services\PmtaSmtpConfigBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Throwable;

class AlphaLinkGlobalTestNativeSender
{
    public function __construct(
        private readonly PmtaCampaignTestTransport $transportFactory,
        private readonly AlphaLinkCampaignTagRenderer $tagRenderer,
        private readonly CampaignRedirectUrlPolicy $redirectUrlPolicy,
        private readonly AlphaLinkBulkInstallVmtaPlanner $vmtaPlanner,
        private readonly PmtaRemoteConfiguration $pmtaRemote,
        private readonly AlphaLinkGlobalTestReverseDnsResolver $rdnsResolver,
    ) {}

    public function pmtaListenerPort(Workspace $workspace): ?int
    {
        $configuredPort = $workspace->infrastructureSettings?->pmta_port ?? config('infrastructure.pmta_port');
        $port = filter_var($configuredPort, FILTER_VALIDATE_INT);

        return $port !== false && $port >= 1 && $port <= 65535 ? (int) $port : null;
    }

    public function isConfigured(Workspace $workspace): bool
    {
        return $this->pmtaListenerPort($workspace) !== null;
    }

    /**
     * Send Global Test messages natively through the workspace's PMTA SMTP ingress,
     * mirroring Edit Campaign's PmtaCampaignTestTransport & AlphaLinkCampaignTagRenderer behavior.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, array<string, mixed>>  $selectedIps
     * @return array{configured: bool, output: string, error: string, exit_code: int|null}
     */
    public function send(
        Workspace $workspace,
        User $user,
        array $data,
        Collection $selectedIps,
        string $decodedHeader,
        string $decodedBody,
    ): array {
        $port = $this->pmtaListenerPort($workspace);
        if ($port === null) {
            return [
                'configured' => false,
                'output' => '',
                'error' => 'Configure a valid workspace PMTA SMTP listener port before testing.',
                'exit_code' => null,
            ];
        }

        $recipients = array_values(array_filter(array_map(
            static fn (mixed $email): string => mb_strtolower(trim((string) $email)),
            (array) ($data['email_test'] ?? []),
        )));
        if ($recipients === []) {
            throw ValidationException::withMessages(['email_test' => 'Enter at least one Email Test address.']);
        }
        foreach ($recipients as $recipient) {
            if (mb_strlen($recipient) > 320 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['email_test' => "Invalid test recipient email address: {$recipient}"]);
            }
        }

        $servers = $workspace->managedServers()
            ->whereIn('id', $data['servers'])
            ->where('is_active', true)
            ->where('is_installed', true)
            ->with(['ips' => fn ($query) => $query->where('is_active', true)->with(['domain:id,name', 'history'])])
            ->get()
            ->keyBy(fn (ManagedServer $server): string => (string) $server->getKey());

        $negativePath = '';
        if (! empty($data['negative'])) {
            $negative = WorkspaceCampaignNegative::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('created_by', $user->getKey())
                ->whereKey($data['negative'])
                ->first();
            $negativePath = $negative ? Storage::disk('local')->path($negative->path) : '';
        }

        $routeOption = (string) $data['option'];
        $submittedHeader = str_replace(["\r\n", "\r"], "\n", $decodedHeader);
        $headerTemplate = preg_replace('/^\s*to\s*:\s*.*$/im', 'To: [to]', $submittedHeader) ?? $submittedHeader;
        if (! preg_match('/^\s*to\s*:/im', $headerTemplate)) {
            $headerTemplate = rtrim($headerTemplate)."\nTo: [to]";
        }

        $placeholderRows = (array) ($data['placeholders'] ?? []);
        $returnPathTemplate = (string) ($data['return_path'] ?? '');
        $domainFallback = trim((string) ($data['domain'] ?? ''));

        $usesSmtpTag = $this->usesSmtpTag([$submittedHeader, $decodedBody, $returnPathTemplate], $placeholderRows);

        if (in_array($routeOption, ['vmta', 'smtp'], true)) {
            foreach ($selectedIps as $choice) {
                $selectedServer = $servers->get((string) ($choice['server_id'] ?? ''));
                if (! $selectedServer instanceof ManagedServer) {
                    throw ValidationException::withMessages(['ips' => 'A selected route is no longer attached to an active server.']);
                }
                $virtualMtaName = trim((string) ($choice['pmta_name'] ?? ''));
                if (preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $virtualMtaName) !== 1) {
                    throw ValidationException::withMessages([
                        'option' => $routeOption === 'smtp'
                            ? 'A selected SMTP route has no valid pushed PMTA name. Configure and push it first.'
                            : 'A selected VMTA has no valid pushed PMTA name. Configure and push it in PMTA VMTA first.',
                    ]);
                }
                if ($routeOption === 'smtp' && ctype_digit((string) ($choice['id'] ?? ''))) {
                    $routeOwnerId = $this->workspaceSmtpRouteOwnerId($workspace, $selectedServer, $choice, $virtualMtaName);
                    if ($routeOwnerId === null) {
                        throw ValidationException::withMessages(['ips' => 'The selected Workspace SMTP route is no longer active.']);
                    }
                    try {
                        $exists = $this->pmtaRemote->workspaceSmtpRouteExists($selectedServer, $routeOwnerId, $virtualMtaName);
                    } catch (Throwable) {
                        throw ValidationException::withMessages([
                            'ips' => 'AlphaLink could not verify the selected Workspace SMTP route on PMTA over SSH.',
                        ]);
                    }
                    if (! $exists) {
                        throw ValidationException::withMessages([
                            'ips' => "PMTA does not have Workspace SMTP route {$virtualMtaName} loaded. Push the Workspace relay first.",
                        ]);
                    }
                }
            }
        } elseif ($usesSmtpTag) {
            foreach ($selectedIps as $choice) {
                $smtpAccountCount = (int) ($choice['smtp_account_count'] ?? 0);
                if ($smtpAccountCount !== 1) {
                    throw ValidationException::withMessages([
                        'header' => $smtpAccountCount === 0
                            ? 'The selected IP has no linked SMTP account to expand [smtp]. Link one active account to this IP or choose Option SMTP.'
                            : 'The selected IP has multiple linked SMTP accounts, so [smtp] is ambiguous. Choose the exact account with Option SMTP.',
                    ]);
                }
            }
        }

        $tagCache = [];
        $repeat = max(1, (int) ($data['repeat'] ?? 1));
        $jobs = [];

        for ($r = 1; $r <= $repeat; $r++) {
            foreach ($selectedIps as $choice) {
                $serverId = (string) ($choice['server_id'] ?? '');
                $server = $servers->get($serverId);
                if (! $server instanceof ManagedServer) {
                    continue;
                }
                $routeKey = $serverId.'|'.(string) ($choice['id'] ?? '');
                $tagCache[$routeKey] ??= $this->resolveRouteTags($workspace, $server, $routeOption, $choice, $domainFallback);
                $routeTags = $tagCache[$routeKey];

                $vmtaName = $routeOption === 'ip'
                    ? $this->vmtaPlanner->ipVirtualMtaName((string) ($choice['ip'] ?? $routeTags['ip']))
                    : (string) ($choice['pmta_name'] ?? '');

                foreach ($recipients as $recipient) {
                    $idx = count($jobs);
                    $tags = [
                        ...$routeTags,
                        'to' => $recipient,
                        'name' => explode('@', $recipient)[0],
                        'date' => now()->toRfc2822String(),
                        'negative' => $negativePath,
                        'token' => '',
                        'click_url' => '',
                        'boundary' => Str::random(30),
                    ];
                    $boundaryCache = [];
                    $renderedHeader = $this->tagRenderer->render($headerTemplate, $tags, $placeholderRows, $idx, $boundaryCache);
                    $renderedBody = $this->tagRenderer->render($decodedBody, $tags, $placeholderRows, $idx, $boundaryCache);
                    $renderedReturnPath = $this->tagRenderer->render($returnPathTemplate, $tags, $placeholderRows, $idx, $boundaryCache);

                    $renderedHeader = $this->headerWithVirtualMta($renderedHeader, $vmtaName);
                    $trackingDomain = (string) ($routeTags['domain'] ?? '');
                    $renderedHeader = $this->redirectUrlPolicy->forceHttpsForHost($renderedHeader, $trackingDomain);
                    $renderedBody = $this->redirectUrlPolicy->forceHttpsForHost($renderedBody, $trackingDomain);
                    $renderedReturnPath = $this->redirectUrlPolicy->forceHttpsForHost($renderedReturnPath, $trackingDomain);

                    $fromAddress = $this->extractHeaderEmailAddress($renderedHeader, 'from');
                    $envelopeFrom = trim($renderedReturnPath) === ''
                        ? $fromAddress
                        : $this->extractEmailAddress($renderedReturnPath);

                    $this->assertValidRenderedAddresses(
                        $submittedHeader,
                        $fromAddress,
                        $envelopeFrom,
                        $routeTags,
                        $routeOption,
                        $domainFallback,
                    );

                    $jobs[] = [
                        'server' => $server,
                        'recipient' => $recipient,
                        'vmta' => $vmtaName,
                        'repeat_index' => $r,
                        'header' => $renderedHeader,
                        'body' => $renderedBody,
                        'envelope_from' => $envelopeFrom,
                    ];
                }
            }
        }

        if ($jobs === []) {
            throw ValidationException::withMessages(['ips' => 'No active selected routes are available to run this test.']);
        }

        $transcript = [];
        $accepted = 0;
        $failures = [];

        foreach ($jobs as $job) {
            /** @var ManagedServer $server */
            $server = $job['server'];
            $transport = null;
            try {
                $transport = $this->transportFactory->forServer($server, $port);
                $transport->start();
                $raw = new RawMessage($job['header']."\r\n\r\n".$job['body']);
                $envelope = new Envelope(new Address($job['envelope_from']), [new Address($job['recipient'])]);
                (new SymfonyMailer($transport))->send($raw, $envelope);
                $accepted++;
                $transcript[] = sprintf(
                    '[%s] Server: %s (%s) | Route: %s | To: %s | Pass %d/%d -> ACCEPTED by PMTA:%d',
                    now()->format('Y-m-d H:i:s'),
                    $server->name,
                    $server->ip_address,
                    $job['vmta'],
                    $job['recipient'],
                    $job['repeat_index'],
                    $repeat,
                    $port,
                );
            } catch (Throwable $exception) {
                $detail = $this->sanitizeSmtpError($exception);
                $failures[] = "{$server->name} ({$job['vmta']} -> {$job['recipient']}): {$detail}";
                $transcript[] = sprintf(
                    '[%s] Server: %s (%s) | Route: %s | To: %s | FAILED: %s',
                    now()->format('Y-m-d H:i:s'),
                    $server->name,
                    $server->ip_address,
                    $job['vmta'],
                    $job['recipient'],
                    $detail,
                );
            } finally {
                if ($transport instanceof SmtpTransport) {
                    try {
                        $transport->stop();
                    } catch (Throwable) {
                    }
                }
            }
        }

        if ($accepted === 0) {
            return [
                'configured' => true,
                'output' => '',
                'error' => implode('; ', array_slice($failures, 0, 5)),
                'exit_code' => 1,
            ];
        }

        if ($failures !== []) {
            $transcript[] = sprintf('Summary: %d accepted, %d failed.', $accepted, count($failures));
        } else {
            $transcript[] = sprintf('Summary: %d / %d test message(s) accepted by PMTA (port %d).', $accepted, count($jobs), $port);
        }

        return [
            'configured' => true,
            'output' => implode("\n", $transcript),
            'error' => '',
            'exit_code' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $choice
     * @return array<string, string>
     */
    private function resolveRouteTags(
        Workspace $workspace,
        ManagedServer $server,
        string $route,
        array $choice,
        string $domainFallback,
    ): array {
        $serverIps = $server->ips;
        $primaryIp = $serverIps->first(fn (ManagedServerIp $ip): bool => (string) $ip->ip === (string) $server->ip_address);
        $ipRecordId = $choice['managed_server_ip_id'] ?? ($route === 'ip' ? ($choice['id'] ?? null) : null);
        $ipModel = $ipRecordId === null
            ? null
            : $serverIps->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->getKey() === (string) $ipRecordId);

        if ($ipModel === null && filled($choice['ip'] ?? null)) {
            $ipModel = $serverIps->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->ip === (string) $choice['ip']);
        }
        $ipModel ??= $primaryIp ?? $serverIps->first();

        $vmtaDomain = trim((string) ($choice['vmta_domain'] ?? ''));
        if ($route === 'vmta' && $vmtaDomain === '') {
            throw ValidationException::withMessages([
                'option' => 'A selected VMTA has no configured domain. Set its domain in PMTA VMTA and push it first.',
            ]);
        }

        $ipString = (string) ($ipModel?->ip ?: $choice['ip'] ?? $server->ip_address);
        $domain = $route !== 'vmta' && $domainFallback !== ''
            ? $domainFallback
            : trim((string) ($ipModel?->dns ?: $ipModel?->domain?->name ?: $choice['dns'] ?? ''));
        $mainDomain = trim((string) ($primaryIp?->dns ?: $primaryIp?->domain?->name ?: 'NA'));
        $ptr = strtolower(rtrim(trim((string) $ipModel?->history?->ptr), '.'));
        if ($ptr === '' || $ptr === 'na') {
            $ptr = $this->rdnsResolver->resolve($ipString, $domain !== '' ? $domain : 'NA');
        }

        return [
            'server' => (string) $server->name.(string) $server->getKey(),
            'main_domain' => $mainDomain,
            'ip' => $ipString,
            'domain' => $domain,
            'rdns' => $ptr,
            'idi' => (string) ($ipModel?->getKey() ?? '0'),
            'idd' => (string) ($ipModel?->sending_domain_id ?? $choice['sending_domain_id'] ?? '0'),
            'ids' => (string) $server->getKey(),
            'ide' => '0',
            'reply' => '',
            'textarea' => '',
            'smtp' => in_array($route, ['ip', 'smtp'], true) ? (string) ($choice['username'] ?? '') : '',
            'smtp_name' => in_array($route, ['ip', 'smtp'], true) ? (string) ($choice['name'] ?? '') : '',
            'tag' => '',
        ];
    }

    /**
     * @param  list<string>  $messageSources
     * @param  list<array<string, mixed>>  $placeholderRows
     */
    private function usesSmtpTag(array $messageSources, array $placeholderRows): bool
    {
        $combined = implode("\n", $messageSources);
        foreach ($placeholderRows as $index => $row) {
            $token = '[placeholder'.((int) $index + 1).']';
            if (stripos($combined, $token) === false) {
                continue;
            }
            $values = $row['data'] ?? [];
            if (is_array($values)) {
                $combined .= "\n".implode("\n", array_map('strval', $values));
            }
        }

        return preg_match('/\[(?:smtp|smtp_name)\]/i', $combined) === 1;
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function workspaceSmtpRouteOwnerId(
        Workspace $workspace,
        ManagedServer $server,
        array $choice,
        string $virtualMtaName,
    ): ?int {
        $routeId = (string) ($choice['id'] ?? '');
        if (! ctype_digit($routeId)) {
            return null;
        }

        $route = ManagedServerMta::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->where('mta_type', 'smtp')
            ->where('is_active', true)
            ->whereKey((int) $routeId)
            ->first(['id', 'ip', 'user_id']);

        if (! $route instanceof ManagedServerMta
            || PmtaSmtpConfigBuilder::workspaceVirtualMtaName((string) $route->ip, (int) $route->getKey()) !== $virtualMtaName) {
            return null;
        }

        return (int) $route->user_id;
    }

    private function headerWithVirtualMta(string $header, string $virtualMtaName): string
    {
        $header = str_replace(["\r\n", "\r"], "\n", $header);
        $header = preg_replace('/^x-virtual-mta\s*:[^\n]*(?:\n[ \t][^\n]*)*/im', '', $header) ?? $header;

        return rtrim($header, "\n")."\nX-virtual-MTA: ".$virtualMtaName;
    }

    private function extractHeaderEmailAddress(string $header, string $field): string
    {
        $line = collect(preg_split('/\r\n|\r|\n/', $header) ?: [])
            ->first(fn (string $l): bool => preg_match('/^\s*'.preg_quote($field, '/').'\s*:/i', $l) === 1);

        return $line === null ? '' : $this->extractEmailAddress((string) preg_replace('/^\s*'.preg_quote($field, '/').'\s*:\s*/i', '', $line));
    }

    private function extractEmailAddress(string $value): string
    {
        if (preg_match('/<\s*([^<>]+)\s*>/', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($value);
    }

    /**
     * @param  array<string, string>  $routeTags
     */
    private function assertValidRenderedAddresses(
        string $submittedHeader,
        string $fromAddress,
        string $envelopeFrom,
        array $routeTags,
        string $routeOption,
        string $domainFallback,
    ): void {
        foreach (['from_address' => [$fromAddress, 'Header'], 'envelope_from' => [$envelopeFrom, 'Return-Path']] as $fieldKey => [$resolved, $label]) {
            if (filter_var($resolved, FILTER_VALIDATE_EMAIL) !== false) {
                continue;
            }
            $errorKey = $fieldKey === 'from_address' ? 'header' : 'return_path';
            $message = "The current {$label} must resolve to a valid email address. Check its tags and the selected server/IP data.";
            if ($fieldKey === 'from_address' && preg_match('/^\s*from\s*:[^\r\n]*\[smtp\]/im', $submittedHeader) === 1) {
                $message = 'The Header From value with [smtp] is not a valid email address. Choose Option SMTP or provide a valid domain.';
            } elseif ($fieldKey === 'from_address'
                && preg_match('/\[domain\]/i', $submittedHeader) === 1
                && trim((string) ($routeTags['domain'] ?? '')) === ''
                && $domainFallback === '') {
                $message = 'The Header uses [domain], but the selected IP has no DNS name and no Domain is selected. Choose a Domain or select an IP with a DNS name.';
            }

            throw ValidationException::withMessages([$errorKey => $message]);
        }
    }

    private function sanitizeSmtpError(Throwable $exception): string
    {
        $raw = $exception->getMessage();
        if ($exception instanceof UnexpectedResponseException && preg_match('/with message\s+"((?:\\\\.|[^"\\\\])*)"/s', $raw, $m) === 1) {
            $raw = stripcslashes($m[1]);
        }
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw) ?? $raw;
        $clean = preg_replace('/\b[A-Za-z0-9+\/_=-]{40,}\b/', '[redacted]', trim($clean)) ?? $clean;

        return mb_substr($clean !== '' ? $clean : 'PMTA transport connection error', 0, 220);
    }
}
