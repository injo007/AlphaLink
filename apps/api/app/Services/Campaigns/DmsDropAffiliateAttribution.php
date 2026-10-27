<?php

namespace App\Services\Campaigns;

use App\Models\ContactListMembership;
use App\Models\Isp;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceSponsor;
use Illuminate\Support\Str;

class DmsDropAffiliateAttribution
{
    /**
     * Resolve DMS's D_<drop id> and s2 conversion contract to a workspace Drop visitor.
     *
     * @param  array<string, mixed>  $sale
     * @return array{visitor: array<string, mixed>, membership: array{list_id: string, contact_id: string}|null}|null
     */
    public function resolve(WorkspaceSponsor $sponsor, array $sale): ?array
    {
        $decodedS1 = $sale['id'] ?? null;
        if (! is_string($decodedS1) || ! preg_match('/^D_([1-9][0-9]*)$/', $decodedS1, $dropMatch)) {
            return null;
        }

        $dropId = filter_var($dropMatch[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($dropId === false) {
            return null;
        }

        $drop = WorkspaceDrop::query()
            ->where('workspace_id', $sponsor->workspace_id)
            ->whereKey($dropId)
            ->first();
        if (! $drop) {
            return null;
        }

        $conversionId = $sale['conversion_id'] ?? null;
        $earning = $sale['earning'] ?? null;
        if ((! is_string($conversionId) && ! is_int($conversionId))
            || trim((string) $conversionId) === ''
            || strlen((string) $conversionId) > 255
            || ! is_numeric($earning)
            || ! is_finite((float) $earning)) {
            return null;
        }

        $parts = explode('-', (string) ($sale['s2'] ?? ''));
        if (count($parts) < 14) {
            return null;
        }

        $appToken = hash('adler32', (string) config('app.name', 'NA'), false);
        $tokenParts = explode('_', $parts[12], 2);
        if (isset($tokenParts[1]) && ! hash_equals($appToken, $tokenParts[1])) {
            return null;
        }

        $ispId = is_numeric($parts[5]) && (int) $parts[5] > 0
            && Isp::query()->where('workspace_id', $sponsor->workspace_id)->whereKey((int) $parts[5])->exists()
            ? (int) $parts[5]
            : null;
        $listId = $this->uuid($parts[4]);
        $contactId = $this->uuid($parts[2]);
        $emailType = $this->emailType($parts[3]);
        $membership = null;

        if ($listId !== null && $contactId !== null && $emailType !== null) {
            $membershipExists = ContactListMembership::query()
                ->where('workspace_id', $sponsor->workspace_id)
                ->where('contact_id', $contactId)
                ->where('status', 'active')
                ->exists();
            $dropListIds = collect($drop->lists ?? [])
                ->map(fn ($id): ?string => is_string($id) ? ($this->uuid($id) ?? $id) : (string) $id);

            if ($membershipExists && $dropListIds->contains($listId)) {
                $membership = ['list_id' => $listId, 'contact_id' => $contactId];
            }
        }

        $visitor = new WorkspaceDropVisitor;

        return [
            'visitor' => [
                'workspace_id' => $sponsor->workspace_id,
                'workspace_drop_id' => $drop->getKey(),
                'type' => 'lead',
                'revenue' => round((float) $earning, 4),
                'revenue_id' => (string) $conversionId,
                'ip' => filter_var($sale['ip'] ?? null, FILTER_VALIDATE_IP) ? $sale['ip'] : 'NA',
                'country' => null,
                'state' => null,
                'city' => null,
                'server_id' => $this->identifier($parts[6]),
                'ip_id' => $this->identifier($parts[7]),
                'domain_id' => $this->identifier($parts[8]),
                'from_id' => $this->identifier($parts[9]),
                'subject_id' => $this->identifier($parts[10]),
                'creative_id' => $this->identifier($parts[11]),
                'isp_id' => $ispId,
                'list_id' => $this->identifier($parts[4]),
                'list_type' => $emailType ?? 'NA',
                'email_id' => $this->identifier($parts[2]) ?? '0',
                'created_at' => $sale['created_at'] ?? now(),
                'updated_at' => now(),
            ],
            'membership' => $membership,
        ];
    }

    private function uuid(string $value): ?string
    {
        if (Str::isUuid($value)) {
            return strtolower($value);
        }

        if (! preg_match('/^[a-f0-9]{32}$/i', $value)) {
            return null;
        }

        return strtolower(substr($value, 0, 8).'-'.substr($value, 8, 4).'-'.substr($value, 12, 4).'-'.substr($value, 16, 4).'-'.substr($value, 20));
    }

    private function identifier(string $value): ?string
    {
        $uuid = $this->uuid($value);
        if ($uuid !== null) {
            return $uuid;
        }

        return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $value) ? $value : null;
    }

    private function emailType(string $type): ?string
    {
        $mapped = match (strtolower(substr($type, 0, 1))) {
            'f' => 'fresh',
            'd' => 'delivery',
            'o' => 'open',
            'c' => 'click',
            'l' => 'lead',
            'u' => 'unsubscribe',
            default => null,
        };

        return $mapped !== null && in_array($mapped, ContactListMembership::CAMPAIGN_TYPES, true) ? $mapped : null;
    }
}
