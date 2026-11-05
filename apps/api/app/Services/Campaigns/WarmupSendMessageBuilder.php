<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\User;
use App\Models\WarmupSendRun;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignNegative;
use App\Services\Infrastructure\AlphaLinkBulkInstallVmtaPlanner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarmupSendMessageBuilder
{
    public function __construct(
        private readonly AlphaLinkCampaignSendCatalog $catalog,
        private readonly AlphaLinkCampaignTagRenderer $tagRenderer,
        private readonly AlphaLinkBulkInstallVmtaPlanner $vmtaPlanner,
    ) {}

    /** @return array<string, mixed> */
    public function context(WarmupSendRun $run, Campaign $campaign, User $user, Workspace $workspace): array
    {
        $revision = $campaign->revisions()->whereKey($run->revision_id)->firstOrFail();
        $payload = (array) $revision->payload;
        $setup = (array) ($payload['send_setup'] ?? []);
        $role = (string) $user->workspaces()->whereKey($workspace->getKey())->firstOrFail()->pivot->role;
        $route = (string) ($setup['route_option'] ?? '');
        $ipType = (string) ($setup['ip_type'] ?? '');
        $serverIds = array_values(array_map('strval', (array) ($setup['server_ids'] ?? [])));
        $ipIds = array_values(array_map('strval', (array) ($setup['ip_ids'] ?? [])));
        $validated = $this->catalog->validateRevisionRouting($workspace, $user, $role, $route, $ipType, $serverIds, $ipIds);
        $options = $this->catalog->options($workspace, $user, $role, [
            'server_ids' => $validated['server_ids'],
            'route' => $route,
            'ip_type' => $ipType,
        ]);
        $serversById = $workspace->managedServers()
            ->whereIn('id', $validated['server_ids'])
            ->where('is_active', true)
            ->where('is_installed', true)
            ->get()
            ->keyBy(fn (ManagedServer $server): string => (string) $server->getKey());
        $servers = collect($validated['server_ids'])->map(fn (string $id): ?ManagedServer => $serversById->get($id))->filter()->values();
        if ($servers->count() !== count($validated['server_ids'])) {
            throw ValidationException::withMessages(['send_setup.server_ids' => 'A selected PMTA server is no longer active and installed.']);
        }
        $choices = collect($options['ips'] ?? [])
            ->filter(fn (array|object $choice): bool => in_array((string) data_get($choice, 'id'), $validated['ip_ids'], true))
            ->map(fn (array|object $choice): array => (array) $choice)
            ->values();
        $choicesByServer = $choices->groupBy(fn (array $choice): string => (string) ($choice['server_id'] ?? ''));
        foreach ($servers as $server) {
            if ($choicesByServer->get((string) $server->getKey(), collect())->isEmpty()) {
                throw ValidationException::withMessages(['send_setup.ip_ids' => 'Select an active IP or MTA route for every selected server.']);
            }
        }

        $configuredPort = $workspace->infrastructureSettings?->pmta_port ?? config('infrastructure.pmta_port');
        $port = filter_var($configuredPort, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw ValidationException::withMessages(['pmta_port' => 'Configure a valid PMTA SMTP listener port before sending.']);
        }
        $negative = filled($setup['negative_id'] ?? null)
            ? WorkspaceCampaignNegative::query()->where('workspace_id', $workspace->getKey())->whereKey($setup['negative_id'])->first()
            : null;
        $negativePath = $negative && Storage::disk('local')->exists($negative->path)
            ? Storage::disk('local')->path($negative->path)
            : '';
        $header = (string) ($payload['header'] ?? '');
        $body = (string) ($payload['html_body'] ?? $payload['body'] ?? '');
        if (trim($header) === '' || trim($body) === '') {
            throw ValidationException::withMessages(['campaign' => 'The saved Warmup revision needs both a message header and body.']);
        }

        return [
            'campaign' => $campaign,
            'revision' => $revision,
            'payload' => $payload,
            'setup' => $setup,
            'route' => $route,
            'port' => (int) $port,
            'servers' => $servers,
            'choices_by_server' => $choicesByServer,
            'header_template' => preg_replace('/^\s*to\s*:\s*.*$/im', 'To: [to]', str_replace(["\r\n", "\r"], "\n", $header)) ?? $header,
            'body_template' => $body,
            'return_path' => (string) ($payload['return_path'] ?? ''),
            'negative_path' => $negativePath,
            'placeholder_rows' => (array) ($setup['placeholder_inputs'] ?? $setup['placeholders'] ?? []),
        ];
    }

    /** @return array{server: ManagedServer, header: string, body: string, envelope_from: string, recipient: string} */
    public function build(array $context, int $position, string $recipient, WarmupSendRun $run): array
    {
        $serverCount = $context['servers']->count();
        $serverIndex = ($position - 1) % $serverCount;
        /** @var ManagedServer $server */
        $server = $context['servers']->get($serverIndex);
        $serverChoices = $context['choices_by_server']->get((string) $server->getKey(), collect())->values();
        $choiceIndex = intdiv(max(0, $position - 1), $serverCount) % $serverChoices->count();
        $choice = $serverChoices->get($choiceIndex);
        $routeName = match ($context['route']) {
            'ip' => $this->vmtaPlanner->ipVirtualMtaName((string) ($choice['ip'] ?? '')),
            'smtp', 'vmta' => trim((string) ($choice['pmta_name'] ?? '')),
        };
        if ($routeName === '' || preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $routeName) !== 1) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'The selected PMTA route has no valid pushed Virtual MTA name. Refresh routes and push the selected route before sending.']);
        }

        $ipRows = ManagedServerIp::query()
            ->where('workspace_id', $context['campaign']->workspace_id)
            ->where('managed_server_id', $server->getKey())
            ->where('is_active', true)
            ->with(['domain:id,name,is_our', 'history'])
            ->orderBy('id')
            ->get();
        $routeIp = $ipRows->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->getKey() === (string) ($choice['managed_server_ip_id'] ?? $choice['id'] ?? ''))
            ?? $ipRows->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->ip === (string) ($choice['ip'] ?? ''));
        $domain = $context['route'] === 'vmta'
            ? trim((string) ($choice['vmta_domain'] ?? ''))
            : trim((string) ($context['setup']['domain'] ?? ($choice['dns'] ?? $routeIp?->dns ?? '')));
        if ($context['route'] === 'vmta' && $domain === '') {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'The selected VMTA has no configured sending domain.']);
        }

        $cache = [];
        $tags = [
            'server' => (string) $server->name.(string) $server->getKey(),
            'ip' => (string) ($routeIp?->ip ?? $choice['ip'] ?? $server->ip_address),
            'rdns' => strtolower(rtrim(trim((string) ($routeIp?->history?->ptr ?: $domain)), '.')),
            'domain' => $domain,
            'to' => $recipient,
            'name' => explode('@', $recipient)[0],
            'from' => '',
            'subject' => '',
            'date' => now()->toRfc2822String(),
            'negative' => $context['negative_path'],
            'boundary' => Str::random(30),
            'smtp' => in_array($context['route'], ['ip', 'smtp'], true) ? (string) ($choice['username'] ?? '') : '',
            'smtp_name' => in_array($context['route'], ['ip', 'smtp'], true) ? (string) ($choice['name'] ?? '') : '',
            'reply' => '',
            'token' => '',
            'click_url' => '',
        ];
        $header = $this->tagRenderer->render($context['header_template'], $tags, $context['placeholder_rows'], $position - 1, $cache);
        $fromAddress = $this->headerEmail($header, 'from');
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
            $fromAddress = 'warmup@'.$domain;
        }
        $subject = $this->headerValue($header, 'subject');
        if ($subject === '') {
            $subject = trim((string) $context['campaign']->name);
        }
        $tags['from'] = $fromAddress;
        $tags['subject'] = $subject;
        $body = $this->tagRenderer->render($context['body_template'], $tags, $context['placeholder_rows'], $position - 1, $cache);
        $returnPath = $this->tagRenderer->render($context['return_path'], $tags, $context['placeholder_rows'], $position - 1, $cache);
        if (! preg_match('/^\s*to\s*:/im', $header)) {
            $header = rtrim($header)."\nTo: ".$recipient;
        }
        $header = $this->setHeader($header, 'From', $fromAddress);
        $header = $this->setHeader($header, 'Subject', $subject);
        $header = $this->setHeader($header, 'X-virtual-MTA', $routeName);
        $header = $this->setHeader($header, 'X-JOB', (string) $run->getKey());
        $envelopeFrom = trim($returnPath) === '' ? $fromAddress : $this->emailAddress($returnPath);
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['header' => 'Warmup From and Return Path must resolve to valid email addresses before sending.']);
        }
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['recipient' => 'The seed list contains an invalid email address.']);
        }

        return ['server' => $server, 'header' => $header, 'body' => $body, 'envelope_from' => $envelopeFrom, 'recipient' => $recipient];
    }

    private function setHeader(string $header, string $name, string $value): string
    {
        $header = preg_replace('/^'.preg_quote($name, '/').'\s*:[^\n]*(?:\n[ \t][^\n]*)*/im', '', $header) ?? $header;

        return rtrim($header, "\n")."\n{$name}: {$value}";
    }

    private function headerEmail(string $header, string $field): string
    {
        $line = collect(preg_split('/\r\n|\r|\n/', $header) ?: [])
            ->first(fn (string $line): bool => preg_match('/^\s*'.preg_quote($field, '/').'\s*:/i', $line) === 1);

        return $line === null ? '' : $this->emailAddress((string) preg_replace('/^\s*'.preg_quote($field, '/').'\s*:\s*/i', '', $line));
    }

    private function headerValue(string $header, string $field): string
    {
        $line = collect(preg_split('/\r\n|\r|\n/', $header) ?: [])
            ->first(fn (string $line): bool => preg_match('/^\s*'.preg_quote($field, '/').'\s*:/i', $line) === 1);

        return $line === null ? '' : trim((string) preg_replace('/^\s*'.preg_quote($field, '/').'\s*:\s*/i', '', $line));
    }

    private function emailAddress(string $value): string
    {
        if (preg_match('/<\s*([^<>]+)\s*>/', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($value);
    }
}
