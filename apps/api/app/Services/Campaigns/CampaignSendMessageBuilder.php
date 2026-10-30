<?php

namespace App\Services\Campaigns;

use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignSendRun;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignNegative;
use App\Models\WorkspaceOfferAsset;
use App\Services\Infrastructure\DmsBulkInstallVmtaPlanner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Resolve one immutable campaign revision into the PMTA message and route used for delivery. */
class CampaignSendMessageBuilder
{
    public function __construct(
        private readonly DmsCampaignSendCatalog $catalog,
        private readonly DmsCampaignTagRenderer $tagRenderer,
        private readonly DmsBulkInstallVmtaPlanner $vmtaPlanner,
    ) {}

    /** @return array<string, mixed> */
    public function context(CampaignSendRun $run): array
    {
        $run->loadMissing(['campaign', 'revision', 'recipientSnapshot']);
        $revision = $run->revision;
        $setup = data_get($revision?->payload, 'send_setup');
        if (! is_array($setup)) {
            throw ValidationException::withMessages(['campaign' => 'The saved campaign has no send configuration.']);
        }

        $workspace = Workspace::query()->whereKey($run->workspace_id)->where('status', 'active')->firstOrFail();
        $user = User::query()->findOrFail($run->requested_by);
        $membership = $user->workspaces()->whereKey($workspace->getKey())->first();
        if (! $membership) {
            throw ValidationException::withMessages(['campaign' => 'The send owner no longer has access to this workspace.']);
        }
        $role = (string) $membership->pivot->role;
        $route = (string) ($setup['route_option'] ?? '');
        $ipType = (string) ($setup['ip_type'] ?? '');
        $serverIds = array_values(array_map('strval', (array) ($setup['server_ids'] ?? [])));
        $selectedIds = array_values(array_map('strval', (array) ($setup['ip_ids'] ?? [])));
        if (! in_array($route, ['ip', 'vmta', 'smtp'], true) || ! in_array($ipType, ['all', 'ipv4', 'ipv6'], true)) {
            throw ValidationException::withMessages(['send_setup' => 'The saved server route is no longer valid. Edit and save the campaign routing before sending.']);
        }

        $validated = $this->catalog->validateRevisionRouting($workspace, $user, $role, $route, $ipType, $serverIds, $selectedIds);
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
        $servers = collect($validated['server_ids'])
            ->map(fn (string $id): ?ManagedServer => $serversById->get($id))
            ->filter()
            ->values();
        if ($servers->count() !== count($validated['server_ids'])) {
            throw ValidationException::withMessages(['send_setup.server_ids' => 'A selected server is no longer active and installed.']);
        }

        $choices = collect($options['ips'] ?? [])
            ->filter(fn (array|object $choice): bool => in_array((string) data_get($choice, 'id'), $validated['ip_ids'], true))
            ->map(fn (array|object $choice): array => (array) $choice)
            ->values();
        $choicesByServer = $choices->groupBy(fn (array $choice): string => (string) ($choice['server_id'] ?? ''));
        foreach ($servers as $server) {
            if ($choicesByServer->get((string) $server->getKey(), collect())->isEmpty()) {
                throw ValidationException::withMessages(['send_setup.ip_ids' => 'Every selected server must have at least one selected IP or SMTP route.']);
            }
        }

        $snapshot = $run->recipientSnapshot;
        $offer = $workspace->offers()->whereKey($snapshot->offer_id)->firstOrFail();
        $fromIds = array_values(array_map('intval', (array) ($setup['from_asset_ids'] ?? [])));
        $subjectIds = array_values(array_map('intval', (array) ($setup['subject_asset_ids'] ?? [])));
        $fromAssets = $this->assets($workspace, $fromIds, 'from');
        $subjectAssets = $this->assets($workspace, $subjectIds, 'subject');
        if ($fromIds !== [] && count($fromAssets) !== count($fromIds)) {
            throw ValidationException::withMessages(['send_setup.from_asset_ids' => 'A selected From address is no longer active. Save the campaign with a current From address.']);
        }
        if ($subjectIds !== [] && count($subjectAssets) !== count($subjectIds)) {
            throw ValidationException::withMessages(['send_setup.subject_asset_ids' => 'A selected Subject is no longer active. Save the campaign with a current Subject.']);
        }

        $negative = filled($setup['negative_id'] ?? null)
            ? WorkspaceCampaignNegative::query()->where('workspace_id', $workspace->getKey())->whereKey($setup['negative_id'])->first()
            : null;
        $negativePath = $negative && Storage::disk('local')->exists($negative->path)
            ? Storage::disk('local')->path($negative->path)
            : '';
        $configuredPort = $workspace->infrastructureSettings?->pmta_port ?? config('infrastructure.pmta_port');
        $port = filter_var($configuredPort, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw ValidationException::withMessages(['pmta_port' => 'Configure a valid PMTA SMTP listener port before sending.']);
        }

        $context = [
            'run' => $run,
            'campaign' => $run->campaign,
            'revision' => $revision,
            'snapshot' => $snapshot,
            'offer' => $offer,
            'workspace' => $workspace,
            'setup' => $setup,
            'route' => $route,
            'port' => (int) $port,
            'servers' => $servers,
            'choices_by_server' => $choicesByServer,
            'from_assets' => $fromAssets,
            'subject_assets' => $subjectAssets,
            'negative_path' => $negativePath,
            'placeholder_rows' => (array) ($setup['placeholder_inputs'] ?? $setup['placeholders'] ?? []),
            'header_template' => $this->headerTemplate((string) ($revision->payload['header'] ?? '')),
            'body_template' => (string) ($revision->payload['body'] ?? ''),
            'return_path' => (string) ($revision->payload['return_path'] ?? ''),
            'domain_fallback' => trim((string) ($setup['domain'] ?? '')),
        ];

        if (trim($context['body_template']) === '') {
            throw ValidationException::withMessages(['body' => 'The campaign message body is empty. Edit the campaign and add message content before sending.']);
        }

        return $context;
    }

    /** @return array{server: ManagedServer, header: string, body: string, envelope_from: string, recipient: string, route: array<string, mixed>, unsubscribe_url: string} */
    public function build(array $context, CampaignRecipientSnapshotItem $item, string $recipient): array
    {
        $position = (int) $item->position;
        $servers = $context['servers'];
        $serverIndex = ($position - 1) % $servers->count();
        /** @var ManagedServer $server */
        $server = $servers->get($serverIndex);
        $serverChoices = $context['choices_by_server']->get((string) $server->getKey(), collect())->values();
        $choiceIndex = intdiv(max(0, $position - 1), $servers->count()) % $serverChoices->count();
        $choice = $serverChoices->get($choiceIndex);
        $ipRows = ManagedServerIp::query()
            ->where('workspace_id', $context['workspace']->getKey())
            ->where('managed_server_id', $server->getKey())
            ->where('is_active', true)
            ->with(['domain:id,name', 'history'])
            ->orderBy('id')
            ->get();
        $primaryIp = $ipRows->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->ip === (string) $server->ip_address)
            ?? $ipRows->first();
        $routeIp = $ipRows->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->getKey() === (string) ($choice['managed_server_ip_id'] ?? $choice['id'] ?? ''))
            ?? $ipRows->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->ip === (string) ($choice['ip'] ?? ''))
            ?? $primaryIp;

        $vmtaName = match ($context['route']) {
            'ip' => $this->vmtaPlanner->ipVirtualMtaName((string) ($choice['ip'] ?? '')),
            'smtp', 'vmta' => trim((string) ($choice['pmta_name'] ?? '')),
        };
        if ($vmtaName === '' || preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $vmtaName) !== 1) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'The selected PMTA route has no valid pushed Virtual MTA name. Refresh route selections and push the route before sending.']);
        }

        $domain = $context['route'] === 'vmta'
            ? trim((string) ($choice['vmta_domain'] ?? ''))
            : trim((string) ($choice['dns'] ?? $routeIp?->dns ?? $context['domain_fallback']));
        if ($context['route'] === 'vmta' && $domain === '') {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'The selected VMTA has no configured sending domain.']);
        }

        $from = $context['from_assets'] === [] ? ['id' => 0, 'value' => ''] : $context['from_assets'][($position - 1) % count($context['from_assets'])];
        $subject = $context['subject_assets'] === [] ? ['id' => 0, 'value' => ''] : $context['subject_assets'][($position - 1) % count($context['subject_assets'])];
        $unsubscribeUrl = URL::temporarySignedRoute(
            'public.campaign-unsubscribe',
            now()->addYears(10),
            ['campaign' => $context['campaign']->getKey(), 'item' => $item->getKey()],
        );
        $token = $this->campaignToken($context, $item, $choice, $from, $subject);
        $tags = [
            'server' => (string) $server->name.(string) $server->getKey(),
            'main_domain' => (string) ($primaryIp?->dns ?: $primaryIp?->domain?->name ?: 'NA'),
            'ip' => (string) ($routeIp?->ip ?: $choice['ip'] ?? $server->ip_address),
            'domain' => $domain,
            'rdns' => strtolower(rtrim(trim((string) ($routeIp?->history?->ptr ?: $domain)), '.')),
            'idi' => (string) ($routeIp?->getKey() ?? '0'),
            'idd' => (string) ($routeIp?->sending_domain_id ?? $choice['sending_domain_id'] ?? '0'),
            'ids' => (string) $server->getKey(),
            'ide' => (string) $item->contact_id,
            'to' => $recipient,
            'name' => explode('@', $recipient)[0],
            'from' => (string) $from['value'],
            'subject' => (string) $subject['value'],
            'date' => now()->toRfc2822String(),
            'negative' => $context['negative_path'],
            'token' => $token,
            'boundary' => Str::random(30),
            'reply' => $unsubscribeUrl,
            'textarea' => '',
            'smtp' => in_array($context['route'], ['ip', 'smtp'], true) ? (string) ($choice['username'] ?? '') : '',
            'smtp_name' => in_array($context['route'], ['ip', 'smtp'], true) ? (string) ($choice['name'] ?? '') : '',
            'tag' => '',
        ];
        $cache = [];
        $header = $this->tagRenderer->render($context['header_template'], $tags, $context['placeholder_rows'], $position - 1, $cache);
        $body = $this->tagRenderer->render($context['body_template'], $tags, $context['placeholder_rows'], $position - 1, $cache);
        $returnPath = $this->tagRenderer->render($context['return_path'], $tags, $context['placeholder_rows'], $position - 1, $cache);

        if ($context['route'] === 'vmta') {
            $header = $this->replaceDomain($header, $context['domain_fallback'], $domain);
            $body = $this->replaceDomain($body, $context['domain_fallback'], $domain);
            $returnPath = $this->replaceDomain($returnPath, $context['domain_fallback'], $domain);
        }
        $header = $this->setHeader($header, 'X-virtual-MTA', $vmtaName);
        $header = $this->setHeader($header, 'X-ENVID', $context['run']->getKey().'-'.$position);
        $header = $this->setHeader($header, 'X-JOB', (string) $context['run']->getKey());
        $header = $this->setHeader($header, 'List-Unsubscribe', '<'.$unsubscribeUrl.'>');
        $header = $this->setHeader($header, 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $contentType = collect(preg_split('/\r\n|\r|\n/', $header) ?: [])
            ->first(fn (string $line): bool => preg_match('/^\s*content-type\s*:\s*text\/html/i', $line) === 1);
        $body = $this->appendUnsubscribe($body, $unsubscribeUrl, $contentType !== null);
        $fromAddress = $this->headerEmail($header, 'from');
        $envelopeFrom = trim($returnPath) === '' ? $fromAddress : $this->emailAddress($returnPath);
        foreach (['From' => $fromAddress, 'Return-Path' => $envelopeFrom] as $label => $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages([$label === 'From' ? 'header' : 'return_path' => "The campaign {$label} must resolve to a valid email address using its selected server and SMTP tags."]);
            }
        }
        if (! preg_match('/^\s*to\s*:/im', $header)) {
            $header = rtrim($header)."\nTo: ".$recipient;
        }

        return ['server' => $server, 'header' => $header, 'body' => $body, 'envelope_from' => $envelopeFrom, 'recipient' => $recipient, 'route' => $choice, 'unsubscribe_url' => $unsubscribeUrl];
    }

    /** Build every selected server's first message before the first delivery begins. */
    public function assertReady(CampaignSendRun $run): array
    {
        $context = $this->context($run);
        $firstItem = CampaignRecipientSnapshotItem::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('snapshot_id', $run->recipient_snapshot_id)
            ->where('position', $run->start_position)
            ->with('address')
            ->firstOrFail();
        $address = (string) ($firstItem->address?->email ?? '');
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['recipient_snapshot' => 'The saved recipient address is missing or invalid. Rebuild the recipient snapshot before sending.']);
        }
        foreach ($context['servers'] as $index => $server) {
            $position = (int) $run->start_position + $index;
            $probeItem = clone $firstItem;
            $probeItem->setAttribute('position', $position);
            $this->build($context, $probeItem, $address);
        }

        return $context;
    }

    private function campaignToken(array $context, CampaignRecipientSnapshotItem $item, array $choice, array $from, array $subject): string
    {
        $parts = [
            str_replace('-', '', (string) $context['campaign']->getKey()),
            str_replace('-', '', (string) $context['snapshot']->getKey()),
            str_replace('-', '', (string) $item->contact_id),
            (string) $item->source_email_type,
            str_replace('-', '', (string) $item->source_list_id),
            (string) ($context['setup']['isp_id'] ?? '0'),
            str_replace('-', '', (string) ($choice['server_id'] ?? '0')),
            str_replace('-', '', (string) ($choice['managed_server_ip_id'] ?? $choice['id'] ?? '0')),
            str_replace('-', '', (string) ($choice['sending_domain_id'] ?? '0')),
            (string) ($from['id'] ?? '0'),
            (string) ($subject['id'] ?? '0'),
            (string) ($context['setup']['creative_asset_id'] ?? '0'),
            Str::random(18).'_'.hash('adler32', (string) config('app.name', 'NA'), false),
        ];
        $parts[] = hash('adler32', implode('_', array_slice($parts, 0, 3)), false);

        return rtrim(strtr(base64_encode(implode('-', $parts)), '+/', '-_'), '=');
    }

    /** @return list<array{id: int, value: string}> */
    private function assets(Workspace $workspace, array $ids, string $type): array
    {
        if ($ids === []) {
            return [];
        }
        $assets = WorkspaceOfferAsset::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->where('type', $type)
            ->whereIn('id', $ids)
            ->get(['id', 'value'])
            ->keyBy('id');

        return collect($ids)->map(fn (int $id): ?array => $assets->has($id)
            ? ['id' => $id, 'value' => (string) $assets->get($id)->value]
            : null)->filter()->values()->all();
    }

    private function headerTemplate(string $header): string
    {
        $header = str_replace(["\r\n", "\r"], "\n", $header);
        $header = preg_replace('/^\s*to\s*:\s*.*$/im', 'To: [to]', $header) ?? $header;
        if (! preg_match('/^\s*to\s*:/im', $header)) {
            $header = rtrim($header)."\nTo: [to]";
        }

        return $header;
    }

    private function setHeader(string $header, string $name, string $value): string
    {
        $header = preg_replace('/^'.preg_quote($name, '/').'\s*:[^\n]*(?:\n[ \t][^\n]*)*/im', '', $header) ?? $header;

        return rtrim($header, "\n")."\n{$name}: {$value}";
    }

    private function replaceDomain(string $text, string $old, string $new): string
    {
        if ($old === '' || $new === '' || strcasecmp($old, $new) === 0) {
            return $text;
        }

        return preg_replace('/(?<![A-Za-z0-9.-])'.preg_quote($old, '/').'(?![A-Za-z0-9.-])/i', $new, $text) ?? $text;
    }

    private function headerEmail(string $header, string $field): string
    {
        $line = collect(preg_split('/\r\n|\r|\n/', $header) ?: [])
            ->first(fn (string $line): bool => preg_match('/^\s*'.preg_quote($field, '/').'\s*:/i', $line) === 1);

        return $line === null ? '' : $this->emailAddress((string) preg_replace('/^\s*'.preg_quote($field, '/').'\s*:\s*/i', '', $line));
    }

    private function emailAddress(string $value): string
    {
        if (preg_match('/<\s*([^<>]+)\s*>/', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($value);
    }

    private function appendUnsubscribe(string $body, string $url, bool $html): string
    {
        if ($html) {
            $footer = '<p><a href="'.e($url).'">Unsubscribe</a></p>';
            if (preg_match('/<\/body\s*>/i', $body) === 1) {
                return preg_replace('/<\/body\s*>/i', $footer.'</body>', $body, 1) ?? $body.$footer;
            }

            return $body.$footer;
        }

        return rtrim($body)."\n\nUnsubscribe: {$url}\n";
    }
}
