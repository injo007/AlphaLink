<?php

namespace App\Services;

use App\Models\ManagedServer;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use App\Services\Infrastructure\HostProbeException;
use Hashids\Hashids;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Copies offer creative files to a selected, fingerprint-verified installed server. */
class DmsOfferAssetSharer
{
    private const REMOTE_PUBLIC_PATH = '/var/mini/public';

    public function __construct(
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsInstallerSshSessionFactory $sessions,
    ) {}

    /**
     * @param  Collection<int, WorkspaceOffer>  $offers
     * @param  Collection<int, ManagedServer>  $servers
     * @return list<array{id: string, name: string, status: bool, data: string, uploaded_count: int, skipped_count: int}>
     */
    public function share(Collection $offers, Collection $servers): array
    {
        try {
            $directoryKeys = $offers->mapWithKeys(fn (WorkspaceOffer $offer): array => [
                (string) $offer->getKey() => $this->offerDirectoryKey($offer),
            ])->all();
        } catch (RuntimeException $exception) {
            return $servers->map(fn (ManagedServer $server): array => [
                'id' => (string) $server->getKey(),
                'name' => $server->name,
                'status' => false,
                'data' => $exception->getMessage(),
                'uploaded_count' => 0,
                'skipped_count' => 0,
            ])->values()->all();
        }

        return $servers->map(fn (ManagedServer $server): array => $this->shareToServer($offers, $server, $directoryKeys))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WorkspaceOffer>  $offers
     * @return array{id: string, name: string, status: bool, data: string, uploaded_count: int, skipped_count: int}
     */
    private function shareToServer(Collection $offers, ManagedServer $server, array $directoryKeys): array
    {
        $uploaded = 0;
        $skipped = 0;

        try {
            $credential = $this->credentials->resolve($server);
            $session = $this->sessions->create($server, $credential['secret'], $credential['type']);

            foreach ($offers as $offer) {
                $directory = self::REMOTE_PUBLIC_PATH.'/'.($directoryKeys[(string) $offer->getKey()] ?? throw new RuntimeException('The DMS offer directory could not be resolved.'));
                $directoryResult = $session->exec('mkdir -p -- '.escapeshellarg($directory).' && printf ALPHALINK_OFFER_DIR_READY');
                if (! is_string($directoryResult) || trim($directoryResult) !== 'ALPHALINK_OFFER_DIR_READY') {
                    throw new RuntimeException('The remote creative directory could not be prepared.');
                }

                foreach ($offer->assets()->whereIn('type', ['creative', 'unsubscribe'])->orderBy('id')->get() as $asset) {
                    /** @var WorkspaceOfferAsset $asset */
                    $prepared = $this->prepareAsset($offer, $asset);
                    $remotePath = $directory.'/'.$prepared['filename'];
                    $exists = $session->exec('if [ -f '.escapeshellarg($remotePath).' ]; then printf PRESENT; else printf MISSING; fi');
                    if (! is_string($exists)) {
                        throw new RuntimeException('The remote server connection ended while checking creative files.');
                    }

                    if (trim($exists) === 'PRESENT') {
                        $skipped++;

                        continue;
                    }

                    if (trim($exists) !== 'MISSING') {
                        throw new RuntimeException('The remote creative file status could not be verified.');
                    }

                    $success = $prepared['local_path'] !== null
                        ? $session->uploadFile($prepared['local_path'], $remotePath)
                        : $session->upload((string) $prepared['contents'], $remotePath);
                    if (! $success) {
                        throw new RuntimeException('A creative file could not be uploaded to the remote server.');
                    }

                    $uploaded++;
                }
            }

            return [
                'id' => (string) $server->getKey(),
                'name' => $server->name,
                'status' => true,
                'data' => sprintf('Shared Successfully (%d uploaded, %d already present)', $uploaded, $skipped),
                'uploaded_count' => $uploaded,
                'skipped_count' => $skipped,
            ];
        } catch (Throwable $exception) {
            return [
                'id' => (string) $server->getKey(),
                'name' => $server->name,
                'status' => false,
                'data' => $exception instanceof HostProbeException
                    ? $exception->getMessage()
                    : 'Share failed. Check the saved SSH access and remote /var/mini/public permissions.',
                'uploaded_count' => $uploaded,
                'skipped_count' => $skipped,
            ];
        }
    }

    /** @return array{filename: string, local_path: ?string, contents: ?string} */
    private function prepareAsset(WorkspaceOffer $offer, WorkspaceOfferAsset $asset): array
    {
        if ((string) $asset->workspace_id !== (string) $offer->workspace_id
            || (int) $asset->offer_id !== (int) $offer->getKey()) {
            throw new RuntimeException('The selected offer asset is outside its workspace.');
        }

        $expectedPrefix = sprintf(
            'offers/%s/%d/assets/%s/',
            $offer->workspace_id,
            $offer->getKey(),
            $asset->type,
        );
        if (! str_starts_with($asset->value, $expectedPrefix)) {
            throw new RuntimeException('An offer image is not stored in its workspace asset directory.');
        }

        $filename = substr($asset->value, strlen($expectedPrefix));
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\.(?:jpe?g|png|gif)\z/i', $filename)) {
            throw new RuntimeException('An offer image has an unsupported stored file name.');
        }

        $disk = Storage::disk('local');
        $localPath = $disk->path($asset->value);
        $diskRoot = realpath($disk->path(''));
        $resolvedPath = realpath($localPath);
        if ($resolvedPath === false
            || $diskRoot === false
            || is_link($localPath)
            || ! is_file($resolvedPath)
            || ! str_starts_with($resolvedPath, $diskRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('An offer image is missing or outside AlphaLink storage.');
        }

        return [
            'filename' => $filename,
            'local_path' => $resolvedPath,
            'contents' => null,
        ];
    }

    private function offerDirectoryKey(WorkspaceOffer $offer): string
    {
        $settings = $offer->workspace?->appSettings?->settings ?? [];
        $salt = is_array($settings) ? trim((string) ($settings['hash_token'] ?? '')) : '';
        if ($salt === '') {
            throw new RuntimeException('Set FOLDERS HASH TOKEN in App Settings before sharing offer assets. No server was changed.');
        }

        return (new Hashids($salt))->encode((int) $offer->getKey());
    }
}
