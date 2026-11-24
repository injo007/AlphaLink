<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;
use App\Models\SendingDomainDkimIdentity;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AlphaLinkSendingDomainDkimIdentityManager
{
    public function __construct(private readonly DkimKeyMaterialManager $keyMaterial) {}

    public function ensure(Workspace $workspace, SendingDomain $domain, string $hostname): SendingDomainDkimIdentity
    {
        if ((string) $domain->workspace_id !== (string) $workspace->getKey()) {
            throw new InvalidArgumentException('The DKIM domain does not belong to the selected workspace.');
        }

        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $baseDomain = strtolower(rtrim(trim((string) $domain->name), '.'));
        if (strlen($hostname) > 253
            || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || ($hostname !== $baseDomain && ! str_ends_with($hostname, '.'.$baseDomain))) {
            throw new InvalidArgumentException('The DKIM hostname does not belong to the selected sending domain.');
        }

        return DB::transaction(function () use ($workspace, $domain, $hostname): SendingDomainDkimIdentity {
            Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $existing = SendingDomainDkimIdentity::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('hostname', $hostname)
                ->first();
            if ($existing instanceof SendingDomainDkimIdentity) {
                if ((string) $existing->sending_domain_id !== (string) $domain->getKey() || ! $existing->is_active) {
                    throw new RuntimeException('The DKIM hostname is already assigned to a different or inactive signing identity.');
                }
                $this->keyMaterial->publicRecord($existing->private_key);

                return $existing;
            }

            $privateKey = $this->keyMaterial->generatePrivateKey();

            return SendingDomainDkimIdentity::query()->create([
                'workspace_id' => $workspace->getKey(),
                'sending_domain_id' => $domain->getKey(),
                'hostname' => $hostname,
                'selector' => 'al'.bin2hex(random_bytes(8)),
                'private_key' => $privateKey,
                'public_record' => $this->keyMaterial->publicRecord($privateKey),
                'is_active' => true,
            ]);
        });
    }
}
