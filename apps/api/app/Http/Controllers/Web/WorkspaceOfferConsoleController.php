<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\DownloadWorkspaceOfferSuppressionFile;
use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use App\Models\WorkspaceOfferSuppressionDownload;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderException;
use App\Services\AffiliateNetworks\DmsSponsorOfferImporter;
use App\Services\AffiliateNetworks\DmsSponsorOfferRefresher;
use App\Services\Campaigns\DmsOfferSuppressionFile;
use App\Services\DmsOfferAssetSharer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceOfferConsoleController extends Controller
{
    private const MANAGE_ROLES = ['owner', 'admin'];

    private const NETWORK_ROLES = ['owner', 'admin', 'operator'];

    public function index(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offers = $this->filteredOffers($request, $workspace, $request->boolean('deleted'));

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->presentOffers($offers->get())]);
        }

        return view('console.offers.index', [
            'workspace' => $workspace,
            'sponsors' => $workspace->sponsors()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'countries' => config('dms_install_countries'),
            'verticals' => $workspace->offers()->whereNotNull('vertical')->distinct()->orderBy('vertical')->pluck('vertical'),
            'isNetworkView' => false,
        ]);
    }

    public function suppressionFile(Request $request, string $workspaceId, int $offerId, DmsOfferSuppressionFile $suppressionFiles): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->with('sponsor')->whereKey($offerId)->firstOrFail();
        $hasStoredFile = $suppressionFiles->hasStoredFile($workspace, $offer);
        $savedDownload = $hasStoredFile
            ? WorkspaceOfferSuppressionDownload::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('offer_id', $offer->getKey())
                ->where('status', 'completed')
                ->where('sha256', $offer->suppression_sha256)
                ->latest('id')
                ->first()
            : null;

        return view('console.offers.suppression', [
            'workspace' => $workspace,
            'offer' => $offer,
            'hasStoredFile' => $hasStoredFile,
            'savedFileBytes' => $hasStoredFile ? (int) (Storage::disk('local')->size($offer->suppression_path) ?: 0) : null,
            'savedFileEntryCount' => $savedDownload?->entry_count,
            'canManageSuppressionFiles' => in_array($workspace->pivot->role, self::MANAGE_ROLES, true),
        ]);
    }

    public function downloadSuppression(Request $request, string $workspaceId, int $offerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $sponsor = $workspace->sponsors()->whereKey($offer->sponsor_id)->first();
        abort_unless($sponsor instanceof WorkspaceSponsor && $sponsor->is_active, 422, 'This offer has no active sponsor connection.');

        [$download, $created] = DB::transaction(function () use ($workspace, $offer, $request): array {
            WorkspaceOffer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();
            WorkspaceOfferSuppressionDownload::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('offer_id', $offer->getKey())
                ->whereIn('status', ['queued', 'resolving', 'downloading', 'extracting', 'validating', 'saving'])
                ->where('updated_at', '<', now()->subMinutes(10))
                ->update([
                    'status' => 'failed',
                    'failure_message' => 'Suppression processing stopped without updating for 10 minutes. Check the suppression worker and retry the download.',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            $existing = WorkspaceOfferSuppressionDownload::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('offer_id', $offer->getKey())
                ->whereIn('status', ['queued', 'resolving', 'downloading', 'validating'])
                ->where('updated_at', '>=', now()->subHours(3))
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            return [WorkspaceOfferSuppressionDownload::query()->create([
                'workspace_id' => $workspace->getKey(),
                'offer_id' => $offer->getKey(),
                'requested_by' => $request->user()->getKey(),
                'status' => 'queued',
            ]), true];
        });

        if ($created) {
            DownloadWorkspaceOfferSuppressionFile::dispatch($download->getKey());
        }

        return response()->json(['download' => $this->presentSuppressionDownload($download, $offer)], 202);
    }

    public function suppressionDownloadStatus(Request $request, string $workspaceId, int $offerId, int $downloadId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $download = WorkspaceOfferSuppressionDownload::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('offer_id', $offer->getKey())
            ->whereKey($downloadId)
            ->firstOrFail();
        $this->failStaleSuppressionDownload($download);

        return response()->json(['download' => $this->presentSuppressionDownload($download, $offer)]);
    }

    private function failStaleSuppressionDownload(WorkspaceOfferSuppressionDownload $download): void
    {
        $cutoff = now()->subMinutes(10);
        if (! in_array($download->status, ['queued', 'resolving', 'downloading', 'extracting', 'validating', 'saving'], true)
            || $download->updated_at === null
            || $download->updated_at->greaterThanOrEqualTo($cutoff)) {
            return;
        }

        $updated = WorkspaceOfferSuppressionDownload::query()
            ->whereKey($download->getKey())
            ->whereIn('status', ['queued', 'resolving', 'downloading', 'extracting', 'validating', 'saving'])
            ->where('updated_at', '<', $cutoff)
            ->update([
                'status' => 'failed',
                'failure_message' => 'Suppression processing stopped without updating for 10 minutes. Check the suppression worker and retry the download.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated > 0) {
            $download->refresh();
        }
    }

    /** @return array<string, mixed> */
    private function presentSuppressionDownload(WorkspaceOfferSuppressionDownload $download, WorkspaceOffer $offer): array
    {
        $percent = match ($download->status) {
            'completed' => 100,
            'saving' => $download->entry_count > 0
                ? min(99, (int) floor($download->processed_entries * 100 / $download->entry_count))
                : 0,
            default => $download->total_bytes > 0
                ? min(99, (int) floor($download->downloaded_bytes * 100 / $download->total_bytes))
                : null,
        };

        return [
            'id' => $download->getKey(),
            'status' => $download->status,
            'downloaded_bytes' => $download->downloaded_bytes,
            'total_bytes' => $download->total_bytes,
            'percent' => $percent,
            'entry_count' => $download->entry_count,
            'processed_entries' => $download->processed_entries,
            'failure_message' => $download->failure_message,
            'manage_url' => route('console.offers.suppression-file.show', [
                $download->workspace_id,
                $offer->getKey(),
            ]),
        ];
    }

    public function networkIndex(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::NETWORK_ROLES);
        $offers = $this->filteredOffers($request, $workspace, false)
            ->where('is_active', true)
            ->whereHas('sponsor', fn (Builder $query) => $query->where('is_active', true))
            ->when($workspace->pivot->role === 'operator', function (Builder $query) use ($request): void {
                $query->whereJsonContains('allowed_users', (int) $request->user()->getKey())
                    ->whereHas('sponsor', fn (Builder $sponsors) => $sponsors->whereJsonContains(
                        'allowed_users',
                        (int) $request->user()->getKey(),
                    ));
            });

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->presentOffers($offers->get(), false)]);
        }

        $sponsors = $workspace->sponsors()
            ->where('is_active', true)
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains(
                'allowed_users',
                (int) $request->user()->getKey(),
            ))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('console.offers.index', [
            'workspace' => $workspace,
            'sponsors' => $sponsors,
            'countries' => config('dms_install_countries'),
            'verticals' => $workspace->offers()->whereNotNull('vertical')->distinct()->orderBy('vertical')->pluck('vertical'),
            'isNetworkView' => true,
        ]);
    }

    public function assets(Request $request, string $workspaceId, int $offerId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::NETWORK_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $isNetworkView = ! in_array($workspace->pivot->role, self::MANAGE_ROLES, true);
        if ($isNetworkView) {
            abort_unless($offer->is_active && $offer->sponsor()->where('is_active', true)->exists(), 404);
            abort_unless(in_array((int) $request->user()->getKey(), $offer->allowed_users ?? [], true), 404);
            abort_unless($offer->sponsor()->whereJsonContains('allowed_users', (int) $request->user()->getKey())->exists(), 404);
        }
        $typeMap = [
            'from' => 'from',
            'subjects' => 'subject',
            'creatives' => 'creative',
            'unsubscribe' => 'unsubscribe',
            'html' => 'html',
        ];
        $type = $request->query('type');
        if ($type !== null) {
            abort_unless(isset($typeMap[$type]), 404);
        }

        $assets = $offer->assets()
            ->when($type !== null, fn ($query) => $query->where('type', $typeMap[$type]))
            ->orderBy('id');
        if ($isNetworkView) {
            $assets->where('is_active', true);
        }

        if ($request->wantsJson()) {
            $assetRows = $assets->get();

            return response()->json(['count' => $assetRows->count(), 'data' => $assetRows->map(fn (WorkspaceOfferAsset $asset): array => [
                'id' => $asset->getKey(),
                'type' => $asset->type,
                'value' => $asset->value,
                'file_url' => in_array($asset->type, ['creative', 'unsubscribe'], true)
                    ? route('public.offer-assets.file', [$workspace->getKey(), $offer->getKey(), $asset->getKey()])
                    : null,
                'is_active' => $asset->is_active,
                'created_at' => $asset->created_at?->toDateTimeString(),
            ])->values()]);
        }

        $counts = $offer->assets()
            ->when($isNetworkView, fn ($query) => $query->where('is_active', true))
            ->selectRaw('type, COUNT(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return view('console.offers.assets', [
            'workspace' => $workspace,
            'offer' => $offer,
            'isNetworkView' => $isNetworkView,
            'assetCounts' => [
                'from' => (int) ($counts['from'] ?? 0),
                'subjects' => (int) ($counts['subject'] ?? 0),
                'creatives' => (int) ($counts['creative'] ?? 0),
                'unsubscribe' => (int) ($counts['unsubscribe'] ?? 0),
                'html' => (int) ($counts['html'] ?? 0),
            ],
        ]);
    }

    public function uploadAssetsForm(Request $request, string $workspaceId, int $offerId, string $type): View
    {
        abort_unless(in_array($type, ['creative', 'unsubscribe'], true), 404);
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();

        return view('console.offers.upload-assets', [
            'workspace' => $workspace,
            'offer' => $offer,
            'type' => $type,
            'maxFiles' => $type === 'creative' ? 20 : 1,
        ]);
    }

    public function uploadAssets(Request $request, string $workspaceId, int $offerId, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['creative', 'unsubscribe'], true), 404);
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $maxFiles = $type === 'creative' ? 20 : 1;
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => ['required', 'image', 'mimes:jpg,jpeg,png,gif', 'max:2048'],
        ]);

        $disk = Storage::disk('local');
        $storedPaths = [];
        try {
            /** @var UploadedFile $file */
            foreach ($data['files'] as $file) {
                $extension = strtolower($file->extension() ?: '');
                abort_unless(in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true), 422, 'Unsupported image type.');
                $filename = sprintf('%s-%s.%s', $type === 'creative' ? 'c' : 'u', str()->random(20), $extension);
                $path = sprintf('offers/%s/%d/assets/%s/%s', $workspace->getKey(), $offer->getKey(), $type, $filename);
                if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
                    throw new \RuntimeException('The offer image could not be stored.');
                }
                $storedPaths[] = $path;
            }

            $replacedPaths = [];
            DB::transaction(function () use ($offer, $workspace, $request, $type, $storedPaths, &$replacedPaths): void {
                $lockedOffer = WorkspaceOffer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();
                if ($type === 'unsubscribe') {
                    $previous = $lockedOffer->assets()->where('type', 'unsubscribe')->get();
                    foreach ($previous as $asset) {
                        if (str_starts_with($asset->value, sprintf('offers/%s/%d/assets/unsubscribe/', $workspace->getKey(), $offer->getKey()))) {
                            $replacedPaths[] = $asset->value;
                        }
                        $asset->delete();
                    }
                }

                foreach ($storedPaths as $path) {
                    $lockedOffer->assets()->create([
                        'workspace_id' => $workspace->getKey(),
                        'type' => $type,
                        'value' => $path,
                        'is_active' => true,
                        'created_by' => $request->user()->getKey(),
                    ]);
                }
            });
            foreach ($replacedPaths as $path) {
                $disk->delete($path);
            }
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                $disk->delete($path);
            }
            throw $exception;
        }

        $this->audit($workspace, $request, 'offer.assets_uploaded', $offer, [
            'type' => $type,
            'count' => count($storedPaths),
        ]);

        return response()->json(['data' => 'Added successfully', 'count' => count($storedPaths)]);
    }

    public function assetFile(Request $request, string $workspaceId, int $offerId, int $assetId)
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::NETWORK_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $asset = $offer->assets()->whereKey($assetId)->whereIn('type', ['creative', 'unsubscribe'])->firstOrFail();
        $isManager = in_array($workspace->pivot->role, self::MANAGE_ROLES, true);
        if (! $isManager) {
            abort_unless(
                $offer->is_active
                    && $asset->is_active
                    && in_array((int) $request->user()->getKey(), $offer->allowed_users ?? [], true)
                    && $offer->sponsor()->where('is_active', true)
                        ->whereJsonContains('allowed_users', (int) $request->user()->getKey())->exists(),
                404,
            );
        }

        $expectedDirectory = sprintf('offers/%s/%d/assets/%s/', $workspace->getKey(), $offer->getKey(), $asset->type);
        abort_unless(str_starts_with($asset->value, $expectedDirectory), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($asset->value), 404);

        return response()->file($disk->path($asset->value), [
            'Content-Disposition' => 'inline; filename="'.basename($asset->value).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function publicAssetFile(string $workspaceId, int $offerId, int $assetId)
    {
        $workspace = Workspace::query()->whereKey($workspaceId)->where('status', 'active')->firstOrFail();
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $asset = $offer->assets()->whereKey($assetId)->whereIn('type', ['creative', 'unsubscribe'])->firstOrFail();
        $expectedDirectory = sprintf('offers/%s/%d/assets/%s/', $workspace->getKey(), $offer->getKey(), $asset->type);
        abort_unless(str_starts_with($asset->value, $expectedDirectory), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($asset->value), 404);

        return response()->file($disk->path($asset->value), [
            'Content-Disposition' => 'inline; filename="'.basename($asset->value).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function addAssets(Request $request, string $workspaceId, int $offerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['from', 'subject'])],
            'assets' => ['required', 'array', 'min:1'],
            'assets.*' => ['required', 'string', 'max:9999'],
        ]);
        $values = array_values(array_unique(array_filter(array_map('trim', $data['assets']))));
        if ($values === []) {
            throw ValidationException::withMessages(['assets' => 'Enter at least one non-empty value.']);
        }

        DB::transaction(function () use ($offer, $request, $data, $values, $workspace): void {
            foreach ($values as $value) {
                $exists = $offer->assets()->where('type', $data['type'])->where('value', $value)->exists();
                if (! $exists) {
                    $offer->assets()->create([
                        'workspace_id' => $workspace->getKey(),
                        'type' => $data['type'],
                        'value' => $value,
                        'is_active' => true,
                        'created_by' => $request->user()->getKey(),
                    ]);
                }
            }
        });

        $this->audit($workspace, $request, 'offer.assets_added', $offer, ['type' => $data['type'], 'count' => count($values)]);

        return response()->json(['data' => 'Added successfully']);
    }

    public function updateAsset(Request $request, string $workspaceId, int $assetId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $asset = WorkspaceOfferAsset::query()->where('workspace_id', $workspace->getKey())->whereKey($assetId)->firstOrFail();
        abort_unless(in_array($asset->type, ['from', 'subject'], true), 404);
        $data = $request->validate(['value' => ['required', 'string', 'max:9999']]);
        $asset->forceFill(['value' => trim($data['value'])])->save();
        $this->audit($workspace, $request, 'offer.asset_updated', $asset->offer, ['asset_id' => $asset->getKey(), 'type' => $asset->type]);

        return response()->json(['data' => 'Edited successfully']);
    }

    public function setAssetStatus(Request $request, string $workspaceId, int $assetId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $asset = WorkspaceOfferAsset::query()->where('workspace_id', $workspace->getKey())->whereKey($assetId)->firstOrFail();
        abort_unless(in_array($asset->type, ['from', 'subject', 'creative', 'html'], true), 404);
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $asset->forceFill(['is_active' => (bool) $data['status']])->save();
        $this->audit($workspace, $request, 'offer.asset_status_changed', $asset->offer, ['asset_id' => $asset->getKey(), 'is_active' => (bool) $data['status']]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function setAssetStatuses(Request $request, string $workspaceId, int $offerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
            'status' => ['required', 'boolean'],
        ]);
        $ids = array_map('intval', $data['ids']);
        $selected = $offer->assets()->whereIn('id', $ids)->get(['id', 'type']);
        abort_unless($selected->count() === count($ids), 404);

        // DMS bulk status changes deliberately exclude unsubscribe assets.
        $eligibleIds = $selected
            ->whereIn('type', ['from', 'subject', 'creative', 'html'])
            ->pluck('id')
            ->all();
        $updated = $eligibleIds === [] ? 0 : $offer->assets()
            ->whereIn('id', $eligibleIds)
            ->update(['is_active' => (bool) $data['status']]);

        $this->audit($workspace, $request, 'offer.assets_status_changed', $offer, [
            'asset_ids' => $ids,
            'updated_count' => $updated,
            'is_active' => (bool) $data['status'],
        ]);

        return response()->json(['data' => 'Changed successfully', 'updated' => $updated]);
    }

    public function deleteAsset(Request $request, string $workspaceId, int $assetId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $asset = WorkspaceOfferAsset::query()->where('workspace_id', $workspace->getKey())->whereKey($assetId)->firstOrFail();
        abort_unless(in_array($asset->type, ['from', 'subject'], true), 404);
        $offer = $asset->offer;
        $asset->delete();
        $this->audit($workspace, $request, 'offer.asset_deleted', $offer, ['asset_id' => $asset->getKey(), 'type' => $asset->type]);

        return response()->json(['data' => 'Deleted successfully']);
    }

    public function create(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);

        return view('console.offers.form', [
            'workspace' => $workspace,
            'offer' => null,
            'sponsors' => $workspace->sponsors()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'countries' => config('dms_install_countries'),
            'canImport' => true,
        ]);
    }

    public function import(Request $request, string $workspaceId, DmsSponsorOfferImporter $importer): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $data = $request->validate([
            'sponsor' => ['required', 'integer', 'min:1'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'max:255'],
        ]);
        $sponsor = $workspace->sponsors()->where('is_active', true)->whereKey($data['sponsor'])->firstOrFail();

        try {
            $imported = $importer->import($workspace, $sponsor, $request->user(), array_values($data['ids']));
        } catch (ConnectionException|DmsAffiliateProviderException) {
            return response()->json([
                'message' => 'The affiliate provider request failed before offer records were saved. Check the sponsor API settings and retry.',
            ], 502);
        }

        return response()->json(['data' => 'Imported '.$imported.' offers']);
    }

    public function edit(Request $request, string $workspaceId, int $offerId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->with('assets')->whereKey($offerId)->firstOrFail();
        $sponsors = $workspace->sponsors()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereKey($offer->sponsor_id))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('console.offers.form', [
            'workspace' => $workspace,
            'offer' => $offer,
            'sponsors' => $sponsors,
            'countries' => config('dms_install_countries'),
            'canImport' => false,
        ]);
    }

    public function users(Request $request, string $workspaceId, int $offerId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();

        return view('console.offers.users', [
            'workspace' => $workspace,
            'offer' => $offer,
            'offers' => collect([$offer]),
            'users' => $this->operatorUsers($workspace),
            'bulk' => false,
        ]);
    }

    public function bulkUsers(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $offers = $workspace->offers()->whereKey($data['ids'])->orderBy('name')->get();
        abort_unless($offers->count() === count($data['ids']), 404);

        return view('console.offers.users', [
            'workspace' => $workspace,
            'offer' => null,
            'offers' => $offers,
            'users' => $this->operatorUsers($workspace),
            'bulk' => true,
        ]);
    }

    public function shareAssetsForm(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::NETWORK_ROLES);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $offers = $this->shareableOffers($workspace, $request->user())
            ->whereKey($data['ids'])
            ->with('sponsor:id,name')
            ->orderBy('id')
            ->get();
        abort_unless($offers->count() === count($data['ids']), 404);

        $servers = $this->shareableServers($workspace, $request->user())
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('console.offers.share-assets', [
            'workspace' => $workspace,
            'offers' => $offers,
            'servers' => $servers,
            'hashTokenConfigured' => filled($workspace->appSettings?->settings['hash_token'] ?? null),
        ]);
    }

    public function shareAssets(Request $request, string $workspaceId, DmsOfferAssetSharer $sharer): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::NETWORK_ROLES);
        $data = $request->validate([
            'offers' => ['required', 'array', 'min:1', 'max:50'],
            'offers.*' => ['required', 'integer', 'distinct'],
            'servers' => ['required', 'array', 'min:1', 'max:5'],
            'servers.*' => ['required', 'uuid', 'distinct'],
        ]);
        $offers = $this->shareableOffers($workspace, $request->user())
            ->whereKey($data['offers'])
            ->orderBy('id')
            ->get();
        abort_unless($offers->count() === count($data['offers']), 404);

        $servers = $this->shareableServers($workspace, $request->user())
            ->whereKey($data['servers'])
            ->orderBy('name')
            ->get();
        abort_unless($servers->count() === count($data['servers']), 404);

        $results = $sharer->share($offers, $servers);
        foreach ($results as $result) {
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $request->user()->getKey(),
                'action' => $result['status'] ? 'offer.assets_shared' : 'offer.assets_share_failed',
                'subject_type' => 'managed_server',
                'subject_id' => $result['id'],
                'request_id' => $request->attributes->get('request_id'),
                'properties' => [
                    'offer_ids' => $offers->modelKeys(),
                    'uploaded_count' => $result['uploaded_count'],
                    'skipped_count' => $result['skipped_count'],
                ],
                'occurred_at' => now(),
            ]);
        }

        return response()->json($results);
    }

    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $data = $this->validatedOffer($request, $workspace, false);
        $sponsor = $workspace->sponsors()->whereKey($data['sponsor_id'])->where('is_active', true)->firstOrFail();

        $offer = DB::transaction(function () use ($data, $request, $sponsor, $workspace): WorkspaceOffer {
            $this->assertOfferIdAvailable($workspace, $sponsor, $data['offer_id']);
            $offer = $workspace->offers()->create($this->offerAttributes($data) + [
                'sponsor_id' => $sponsor->getKey(),
                'status' => 'live',
                'allowed_users' => $sponsor->allowed_users ?? [],
                'is_active' => false,
            ]);
            $this->saveAssets($offer, $request, $data);

            return $offer;
        });

        $this->audit($workspace, $request, 'offer.created', $offer);

        return response()->json(['data' => 'Saved successfully']);
    }

    public function update(Request $request, string $workspaceId, int $offerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $data = $this->validatedOffer($request, $workspace, true);
        $sponsor = $workspace->sponsors()->whereKey($data['sponsor_id'])->where('is_active', true)->firstOrFail();

        DB::transaction(function () use ($data, $offer, $sponsor, $workspace): void {
            $this->assertOfferIdAvailable($workspace, $sponsor, $data['offer_id'], $offer->getKey());
            $offer->fill($this->offerAttributes($data) + ['sponsor_id' => $sponsor->getKey()])->save();
        });
        $this->audit($workspace, $request, 'offer.updated', $offer);

        return response()->json(['data' => 'Offer Updated']);
    }

    public function setActive(Request $request, string $workspaceId, int $offerId, DmsOfferSuppressionFile $suppressionFiles): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $activate = (bool) $data['status'];

        if ($activate && ! $this->isOfferReady($offer, $suppressionFiles)) {
            return response()->json([
                'data' => 'Something is empty, all fields must be filled (redirect, status, sup file ...)',
            ], 400);
        }

        $offer->forceFill(['is_active' => $activate])->save();
        $this->audit($workspace, $request, 'offer.active_changed', $offer, ['is_active' => $activate]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function setBusinessStatus(Request $request, string $workspaceId, int $offerId, DmsSponsorOfferRefresher $refresher): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'string', Rule::in(WorkspaceOffer::STATUSES)]]);
        $offer->forceFill(['status' => $data['status']])->save();
        $this->audit($workspace, $request, 'offer.status_changed', $offer, ['status' => $data['status']]);

        if ($data['status'] === 'live') {
            $refresh = $refresher->refresh($workspace, $offer, $request->user());
            $offer->refresh();
            $this->audit($workspace, $request, 'offer.provider_refresh_finished', $offer, [
                'outcome' => $refresh['outcome'],
                'html_creatives_added' => $refresh['html_creatives_added'],
            ]);
        }

        return response()->json(['data' => 'Changed successfully']);
    }

    public function destroy(Request $request, string $workspaceId, int $offerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->whereKey($offerId)->firstOrFail();
        $offer->forceFill([
            'suppression_path' => null,
            'suppression_sha256' => null,
            'suppression_format' => null,
            'suppression_updated_at' => null,
        ])->save();
        $offer->delete();
        $this->audit($workspace, $request, 'offer.deleted', $offer);

        return response()->json(['data' => 'Offer deleted']);
    }

    public function restore(Request $request, string $workspaceId, int $offerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $offer = $workspace->offers()->onlyTrashed()->whereKey($offerId)->firstOrFail();
        $offer->restore();
        $this->audit($workspace, $request, 'offer.restored', $offer);

        return response()->json(['data' => 'Offer restored']);
    }

    public function updateUsers(Request $request, string $workspaceId, ?int $offerId = null): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $bulk = $offerId === null;
        $rules = [
            'users' => ['nullable', 'array'],
            'users.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->getKey())->where('role', 'operator'),
            ],
        ];
        if ($bulk) {
            $rules['offers'] = ['required', 'array', 'min:1'];
            $rules['offers.*'] = ['required', 'integer', 'distinct'];
        }
        $data = $request->validate($rules);
        $ids = $bulk ? array_map('intval', $data['offers']) : [$offerId];
        $offers = $workspace->offers()->whereKey($ids)->orderBy('id')->get();
        abort_unless($offers->count() === count($ids), 404);
        $userIds = array_values(array_unique(array_map('intval', $data['users'] ?? [])));

        DB::transaction(function () use ($offers, $userIds, $workspace, $request): void {
            foreach ($offers as $offer) {
                $offer->forceFill(['allowed_users' => $userIds])->save();
                $this->audit($workspace, $request, 'offer.allowed_users_updated', $offer, ['allowed_user_ids' => $userIds]);
            }
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    /** @return Builder<WorkspaceOffer> */
    private function filteredOffers(Request $request, Workspace $workspace, bool $deleted): Builder
    {
        return WorkspaceOffer::query()->where('workspace_id', $workspace->getKey())
            ->when($deleted, fn (Builder $query) => $query->onlyTrashed())
            ->whereHas('sponsor', fn (Builder $query) => $query->where('is_active', true))
            ->with('sponsor:id,name')
            ->withCount([
                'assets as from_count' => fn (Builder $query) => $query->where('type', 'from')->where('is_active', true),
                'assets as subjects_count' => fn (Builder $query) => $query->where('type', 'subject')->where('is_active', true),
            ])
            ->when($request->filled('sponsor') && $request->input('sponsor') !== '0', fn (Builder $query) => $query->where('sponsor_id', $request->integer('sponsor')))
            ->when($request->filled('vertical'), fn (Builder $query) => $query->where('vertical', $request->string('vertical')->toString()))
            ->when($request->filled('status') && $request->input('status') !== '0', fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('geo') && strtoupper((string) $request->input('geo')) !== 'ALL', fn (Builder $query) => $query->whereJsonContains('geo_target', strtoupper((string) $request->input('geo'))))
            ->orderByDesc('id');
    }

    /** @param Collection<int, WorkspaceOffer> $offers
     * @return array<int, array<string, mixed>>
     */
    private function presentOffers(Collection $offers, bool $includeAssignments = true): array
    {
        return $offers->map(fn (WorkspaceOffer $offer): array => [
            'id' => $offer->getKey(),
            'offer_id' => $offer->offer_id,
            'sponsor_id' => $offer->sponsor_id,
            'sponsor' => ['name' => $offer->sponsor?->name],
            'name' => $offer->name,
            'type' => $offer->type,
            'vertical' => $offer->vertical,
            'geo_target' => $offer->geo_target ?? [],
            'payout' => (string) $offer->payout,
            'suppression_updated_at' => $offer->suppression_updated_at?->toDateString(),
            'from_count' => $offer->from_count ?? 0,
            'subjects_count' => $offer->subjects_count ?? 0,
            'status' => $offer->status,
            ...($includeAssignments ? ['allowed_users' => $offer->allowed_users ?? []] : []),
            'is_active' => (bool) $offer->is_active,
            'deleted_at' => $offer->deleted_at?->toDateTimeString(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function validatedOffer(Request $request, Workspace $workspace, bool $editing): array
    {
        $rules = [
            'sponsor_id' => ['required', 'integer', Rule::exists('workspace_sponsors', 'id')->where('workspace_id', $workspace->getKey())->where('is_active', true)->whereNull('deleted_at')],
            'offer_id' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(WorkspaceOffer::TYPES)],
            'vertical' => ['required', 'string', 'max:255'],
            'payout' => ['required', 'numeric', 'min:0', 'max:9999'],
            'geo_target' => ['required', 'array', 'min:1'],
            'geo_target.*' => ['required', 'string', 'max:32'],
            'allowed_days' => ['required', 'array', 'min:1'],
            'allowed_days.*' => ['required', 'string', 'distinct', Rule::in(WorkspaceOffer::ALLOWED_DAYS)],
            'note' => ['nullable', 'string', 'max:9999'],
            'redirect' => ['required', 'url', 'max:65535'],
            'unsubscribe' => ['nullable', 'url', 'max:65535'],
        ];
        if (! $editing) {
            $rules['from'] = ['required', 'array', 'min:1'];
            $rules['from.*'] = ['required', 'string', 'max:255'];
            $rules['subjects'] = ['required', 'array', 'min:1'];
            $rules['subjects.*'] = ['required', 'string', 'max:9999'];
        }

        $data = $request->validate($rules);
        $data['type'] = strtolower($data['type']);
        $data['vertical'] = strtolower(trim($data['vertical']));
        $data['geo_target'] = array_values(array_unique(array_map('strtoupper', $data['geo_target'])));
        $data['allowed_days'] = array_values(array_unique($data['allowed_days']));

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function offerAttributes(array $data): array
    {
        return [
            'offer_id' => trim($data['offer_id']),
            'name' => trim($data['name']),
            'type' => $data['type'],
            'vertical' => $data['vertical'],
            'geo_target' => $data['geo_target'],
            'payout' => $data['payout'],
            'redirect' => $data['redirect'],
            'unsubscribe' => $data['unsubscribe'] ?? null,
            'note' => $data['note'] ?? null,
            'allowed_days' => $data['allowed_days'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function saveAssets(WorkspaceOffer $offer, Request $request, array $data): void
    {
        foreach (['from' => 'from', 'subjects' => 'subject'] as $field => $type) {
            $values = array_values(array_unique(array_filter(array_map(
                fn (string $value): string => trim($value),
                $data[$field],
            ))));
            foreach ($values as $value) {
                $offer->assets()->create([
                    'workspace_id' => $offer->workspace_id,
                    'type' => $type,
                    'value' => $value,
                    'is_active' => true,
                    'created_by' => $request->user()->getKey(),
                ]);
            }
        }
    }

    private function assertOfferIdAvailable(Workspace $workspace, WorkspaceSponsor $sponsor, string $offerId, ?int $exceptId = null): void
    {
        $exists = $workspace->offers()->withTrashed()
            ->where('sponsor_id', $sponsor->getKey())
            ->where('offer_id', trim($offerId))
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['offer_id' => 'This offer ID already exists for the selected sponsor.']);
        }
    }

    private function isOfferReady(WorkspaceOffer $offer, DmsOfferSuppressionFile $suppressionFiles): bool
    {
        return $offer->redirect !== ''
            && $offer->status === 'live'
            && $suppressionFiles->isEligible($offer)
            && filled($offer->vertical)
            && ! empty($offer->geo_target);
    }

    /** @return Builder<WorkspaceOffer> */
    private function shareableOffers(Workspace $workspace, User $user): Builder
    {
        $query = WorkspaceOffer::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->whereHas('sponsor', fn (Builder $sponsors) => $sponsors->where('is_active', true));

        if ($workspace->pivot->role === 'operator') {
            $query->whereJsonContains('allowed_users', (int) $user->getKey())
                ->whereHas('sponsor', fn (Builder $sponsors) => $sponsors
                    ->where('is_active', true)
                    ->whereJsonContains('allowed_users', (int) $user->getKey()));
        }

        return $query;
    }

    /** @return Builder<ManagedServer> */
    private function shareableServers(Workspace $workspace, User $user): Builder
    {
        $query = ManagedServer::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->where('is_installed', true)
            ->where('state', 'verified')
            ->whereNotNull('expected_host_fingerprint')
            ->whereHas('credential');

        if ($workspace->pivot->role === 'operator') {
            $query->whereHas('allowedUsers', fn (Builder $users) => $users
                ->where('users.id', $user->getKey())
                ->where('managed_server_user.workspace_id', $workspace->getKey()));
        }

        return $query;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    private function operatorUsers(Workspace $workspace): \Illuminate\Database\Eloquent\Collection
    {
        return $workspace->users()->wherePivot('role', 'operator')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']);
    }

    private function auditAsset(Workspace $workspace, Request $request, string $action, WorkspaceOfferAsset $asset, array $properties = []): void
    {
        $this->audit($workspace, $request, $action, $asset->offer, $properties + ['asset_id' => $asset->getKey()]);
    }

    /** @param array<string, mixed> $properties */
    private function audit(Workspace $workspace, Request $request, string $action, WorkspaceOffer $offer, array $properties = []): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'offer',
            'subject_id' => (string) $offer->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<int, string> $roles */
    private function workspaceFor(User $user, string $workspaceId, array $roles): Workspace
    {
        $workspace = $user->workspaces()->where('workspaces.status', 'active')->whereKey($workspaceId)->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, $roles, true), 403);

        return $workspace;
    }
}
