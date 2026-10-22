<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\InfrastructureManager;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\PmtaCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InfrastructureConsoleController extends Controller
{
    public function __construct(private readonly InfrastructureManager $manager) {}

    public function index(Request $request, string $workspaceId, PmtaCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $servers = $workspace->managedServers()->orderByDesc('created_at')->get();
        $domains = $workspace->sendingDomains()->orderByDesc('created_at')->get();
        $plans = $workspace->installationPlans()->orderByDesc('created_at')->limit(10)->get();
        $plan = $request->query('plan')
            ? $workspace->installationPlans()->whereKey($request->query('plan'))->firstOrFail()
            : null;

        return view('console.infrastructure.index', [
            'workspace' => $workspace,
            'servers' => $servers,
            'domains' => $domains,
            'plans' => $plans,
            'plan' => $plan,
            'versions' => $catalog->versions(),
            'formKey' => (string) Str::uuid(),
        ]);
    }

    public function storeServer(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:120'],
            'ip_address' => ['required_without:ip_addresses_text', 'ip'],
            'ip_addresses_text' => ['required_without:ip_address', 'string', 'max:5000'],
            'ssh_port' => ['nullable', 'integer', 'between:1,65535'],
            'ssh_username' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z_][A-Za-z0-9_.-]*$/'],
            'root_password' => ['required_without:ssh_private_key', 'string', 'min:8', 'max:4096'],
            'ssh_private_key' => ['required_without:root_password', 'string', 'max:32768'],
            'expected_host_fingerprint' => ['nullable', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
            'request_key' => ['required', 'uuid'],
        ]);
        if (isset($data['ip_address'], $data['ip_addresses_text'])) {
            throw ValidationException::withMessages(['ip_addresses_text' => 'Provide either one IP or a list of IPs.']);
        }
        if (isset($data['root_password'], $data['ssh_private_key'])) {
            throw ValidationException::withMessages(['ssh_private_key' => 'Provide either a password or a private key.']);
        }

        if (isset($data['ip_addresses_text'])) {
            $ips = preg_split('/[\s,;]+/', trim($data['ip_addresses_text']), -1, PREG_SPLIT_NO_EMPTY);
            Validator::make(['ip_addresses' => $ips], [
                'ip_addresses' => ['required', 'array', 'min:1', 'max:100'],
                'ip_addresses.*' => ['required', 'ip', 'distinct'],
            ])->validate();
            unset($data['ip_addresses_text']);
            $data['ip_addresses'] = $ips;
            $this->manager->registerServers($workspace, $request->user(), $data, $data['request_key'], $request->attributes->get('request_id'));

            return redirect()->route('console.infrastructure.index', $workspace->id)->with('status', count($ips).' servers saved. Host identity and OS still need verification.');
        }

        $this->manager->registerServer($workspace, $request->user(), $data, $data['request_key'], $request->attributes->get('request_id'));

        return redirect()->route('console.infrastructure.index', $workspace->id)->with('status', 'Server saved. Host identity and OS still need verification.');
    }

    public function storeDomain(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate(['name' => ['required', 'string', 'max:253']]);
        $this->manager->registerDomain($workspace, $request->user(), $data['name'], $request->attributes->get('request_id'));

        return redirect()->route('console.infrastructure.index', $workspace->id)->with('status', 'Domain saved. Publish its TXT record to prove ownership.');
    }

    public function discoverHostKey(Request $request, string $workspaceId, string $serverId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        try {
            $this->manager->discoverServerFingerprint($workspace, $request->user(), $server, $request->attributes->get('request_id'));
        } catch (HostProbeException $exception) {
            return redirect()->route('console.infrastructure.index', $workspace->id)->with('warning', $exception->getMessage());
        }

        return redirect()->route('console.infrastructure.index', $workspace->id)->with('status', 'Host key read. Compare the fingerprint below with your provider before preflight.');
    }

    public function preflightServer(Request $request, string $workspaceId, string $serverId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate([
            'expected_host_fingerprint' => ['required', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
        ]);
        try {
            $this->manager->verifyServerHost($workspace, $request->user(), $server, $data['expected_host_fingerprint'], $request->attributes->get('request_id'));
        } catch (HostProbeException $exception) {
            return redirect()->route('console.infrastructure.index', $workspace->id)->with('warning', $exception->getMessage());
        }

        return redirect()->route('console.infrastructure.index', $workspace->id)->with('status', 'Host identity and OS preflight verified.');
    }

    public function verifyDomain(Request $request, string $workspaceId, string $domainId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        $verified = $this->manager->verifyDomain($workspace, $domain, $request->user(), $request->attributes->get('request_id'));

        return redirect()->route('console.infrastructure.index', $workspace->id)
            ->with($verified ? 'status' : 'warning', $verified ? 'Domain ownership verified.' : 'TXT record not found yet. Check its name and value, then try again.');
    }

    public function storePlan(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'server_id' => ['required', 'uuid'],
            'domain_id' => ['required', 'uuid'],
            'pmta_version' => ['required', 'in:4.0,4.5,5.0'],
            'tracking' => ['sometimes', 'accepted'],
            'dkim' => ['sometimes', 'accepted'],
            'dmarc' => ['sometimes', 'accepted'],
            'proxy' => ['sometimes', 'accepted'],
            'spf' => ['sometimes', 'accepted'],
        ]);
        $server = $workspace->managedServers()->whereKey($data['server_id'])->firstOrFail();
        $domain = $workspace->sendingDomains()->whereKey($data['domain_id'])->firstOrFail();
        $options = collect(['tracking', 'dkim', 'dmarc', 'proxy', 'spf'])
            ->mapWithKeys(fn ($key) => [$key => array_key_exists($key, $data)])->all();
        $plan = $this->manager->createPlan($workspace, $request->user(), $server, $domain, $data['pmta_version'], $options, $request->attributes->get('request_id'));

        return redirect()->route('console.infrastructure.index', ['workspace' => $workspace->id, 'plan' => $plan->id]);
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        return $workspace;
    }
}
