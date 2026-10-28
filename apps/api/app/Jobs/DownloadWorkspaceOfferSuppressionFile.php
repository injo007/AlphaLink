<?php

namespace App\Jobs;

use App\Models\WorkspaceOfferSuppressionDownload;
use App\Services\AffiliateNetworks\DmsAffiliateProviderException;
use App\Services\AffiliateNetworks\DmsAffiliateSuppressionDownloadException;
use App\Services\AffiliateNetworks\DmsAffiliateSuppressionRefresher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class DownloadWorkspaceOfferSuppressionFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public int $downloadId)
    {
        $this->onConnection('drop_suppression');
        $this->onQueue('drop-suppression');
    }

    public function handle(DmsAffiliateSuppressionRefresher $refresher): void
    {
        $download = WorkspaceOfferSuppressionDownload::query()->find($this->downloadId);
        if ($download === null || in_array($download->status, ['completed', 'failed'], true)) {
            return;
        }

        $download->forceFill(['status' => 'resolving', 'started_at' => now(), 'failure_message' => null])->save();

        try {
            $lastBytes = -1;
            $lastUpdate = 0.0;
            $lastStage = '';
            $result = $refresher->refreshWithProgress(
                $download->workspace,
                $download->offer,
                function (int $downloaded, ?int $total, string $stage = 'downloading') use ($download, &$lastBytes, &$lastUpdate, &$lastStage): void {
                    $now = microtime(true);
                    if ($stage === $lastStage && $downloaded - $lastBytes < 1_048_576 && $now - $lastUpdate < 1.5) {
                        return;
                    }
                    $download->forceFill([
                        'status' => $stage,
                        'downloaded_bytes' => $downloaded,
                        'total_bytes' => $total,
                    ])->save();
                    $lastBytes = $downloaded;
                    $lastUpdate = $now;
                    $lastStage = $stage;
                },
            );

            if ($result === null) {
                $download->forceFill([
                    'status' => 'failed',
                    'failure_message' => 'The sponsor did not provide a downloadable suppression file.',
                    'finished_at' => now(),
                ])->save();

                return;
            }

            $download->forceFill([
                'status' => 'completed',
                'downloaded_bytes' => $result['bytes'],
                'total_bytes' => $result['bytes'],
                'sha256' => $result['sha256'],
                'entry_count' => $result['count'],
                'format' => $result['format'],
                'failure_message' => null,
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            // Never log exception messages: some provider URLs carry credentials.
            Log::warning('Offer suppression download failed.', [
                'download_id' => $download->getKey(),
                'workspace_id' => $download->workspace_id,
                'offer_id' => $download->offer_id,
                'stage' => $download->status,
                'exception_class' => $exception::class,
                'exception_file' => basename($exception->getFile()),
                'exception_line' => $exception->getLine(),
            ]);

            $failureMessage = match (true) {
                $exception instanceof DmsAffiliateSuppressionDownloadException => $exception->getMessage(),
                $exception instanceof DmsAffiliateProviderException => 'The sponsor endpoint could not be reached safely: '.$exception->getMessage(),
                $exception instanceof ConnectionException => 'AlphaLink could not connect to the sponsor or download host over HTTPS. Check app-server DNS and outbound TCP 443, then retry.',
                $download->status === 'validating' => 'The downloaded file failed suppression validation. Confirm the sponsor file contains valid email addresses, MD5 hashes, or SHA-512 hashes, then retry.',
                default => 'The suppression download failed inside AlphaLink. Diagnostic ID '.$download->getKey().'; check the application log and retry.',
            };

            $download->forceFill([
                'status' => 'failed',
                'failure_message' => $failureMessage,
                'finished_at' => now(),
            ])->save();
        }
    }
}
