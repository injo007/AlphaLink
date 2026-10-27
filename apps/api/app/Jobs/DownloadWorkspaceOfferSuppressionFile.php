<?php

namespace App\Jobs;

use App\Models\WorkspaceOfferSuppressionDownload;
use App\Services\AffiliateNetworks\DmsAffiliateSuppressionRefresher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        } catch (\Throwable) {
            // Provider exceptions may contain credential-bearing URLs; keep the UI/log record sanitized.
            $download->forceFill([
                'status' => 'failed',
                'failure_message' => 'The suppression file could not be downloaded or validated. Check the sponsor connection and try again.',
                'finished_at' => now(),
            ])->save();
        }
    }
}
