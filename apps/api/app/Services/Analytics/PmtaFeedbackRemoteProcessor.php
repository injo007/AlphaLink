<?php

namespace App\Services\Analytics;

use App\Models\ManagedServer;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use RuntimeException;

class PmtaFeedbackRemoteProcessor
{
    public function __construct(
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsInstallerSshSessionFactory $sessions,
        private readonly ImportDmsPmtaFeedback $importer,
    ) {}

    /**
     * @return array{files_seen: int, imported: int, duplicate: int, unmatched: int, archived: int, deferred: int}
     */
    public function process(ManagedServer $server, string $accountingDate): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $accountingDate, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new RuntimeException('The PMTA feedback accounting date is invalid.');
        }

        if (! $server->exists || ! $server->is_active || ! $server->is_installed || $server->state !== 'verified') {
            throw new RuntimeException('PMTA feedback requires an active, installed, verified AlphaLink server.');
        }

        $credential = $this->credentials->resolve($server);
        $session = $this->sessions->create($server, $credential['secret'], $credential['type']);
        $counts = ['files_seen' => 0, 'imported' => 0, 'duplicate' => 0, 'unmatched' => 0, 'archived' => 0, 'deferred' => 0];
        $archiveCandidates = [];
        $hasImportableRows = false;
        $affectedListIds = [];

        foreach ([
            'delivery' => ['base' => 'delivered', 'prefix' => 'd'],
            'bounce' => ['base' => 'bounced', 'prefix' => 'b'],
        ] as $kind => $paths) {
            $baseDirectory = '/etc/pmta/'.$paths['base'];
            $treatedDirectory = $baseDirectory.'/treated';
            $processDirectory = $baseDirectory.'/process/'.$accountingDate;

            if (! $session->isDirectory($treatedDirectory) || ! $session->createDirectory($processDirectory)) {
                throw new RuntimeException('The PMTA feedback directories are unavailable on the selected server.');
            }

            $treatedFiles = $this->matchingFiles($session->listDirectory($treatedDirectory), $paths['prefix'], $accountingDate);
            $processFiles = $this->matchingFiles($session->listDirectory($processDirectory), $paths['prefix'], $accountingDate);
            $processNames = array_fill_keys($processFiles, true);

            foreach ($treatedFiles as $filename) {
                if (isset($processNames[$filename])) {
                    $counts['deferred']++;

                    continue;
                }

                if (! $session->moveFile($treatedDirectory.'/'.$filename, $processDirectory.'/'.$filename)) {
                    throw new RuntimeException('A PMTA feedback file could not be moved into its processing directory.');
                }

                $processNames[$filename] = true;
            }

            $filenames = array_keys($processNames);
            sort($filenames, SORT_STRING);
            foreach ($filenames as $filename) {
                $counts['files_seen']++;
                $remotePath = $processDirectory.'/'.$filename;
                $sizeBefore = $session->fileSize($remotePath);
                $modifiedBefore = $session->fileModifiedAt($remotePath);
                if (! is_int($sizeBefore) || ! is_int($modifiedBefore) || $sizeBefore < 1) {
                    $counts['deferred']++;

                    continue;
                }

                // PMTA can still be appending to a just-rotated CSV. Match DMS's
                // ten-minute accounting delay and require the remote file to be
                // old before copying it into the importer.
                if ($modifiedBefore > now()->subMinutes(10)->getTimestamp()) {
                    $counts['deferred']++;

                    continue;
                }

                $localPath = $this->stageRemoteFile($session, $remotePath, $sizeBefore, $modifiedBefore);
                try {
                    $stream = fopen($localPath, 'rb');
                    if ($stream === false) {
                        throw new RuntimeException('A staged PMTA feedback file could not be opened.');
                    }

                    try {
                        $fileId = hash('sha256', implode("\0", [(string) $server->getKey(), $kind, $accountingDate, $filename]));
                        $result = $this->importer->import($server, $stream, $kind, $fileId, $accountingDate, deferDerivedRebuild: true);
                    } finally {
                        fclose($stream);
                    }
                } finally {
                    if (is_file($localPath)) {
                        unlink($localPath);
                    }
                }

                $counts['imported'] += $result['imported'];
                $counts['duplicate'] += $result['duplicate'];
                $counts['unmatched'] += $result['unmatched'];
                $hasImportableRows = $hasImportableRows || ($result['imported'] + $result['duplicate']) > 0;
                foreach ($result['affected_list_ids'] as $listId) {
                    $affectedListIds[$listId] = true;
                }

                if (($result['imported'] + $result['duplicate']) === 0 || $result['unmatched'] > 0) {
                    $counts['deferred']++;

                    continue;
                }

                $archiveCandidates[] = ['source' => $remotePath, 'base' => $baseDirectory];
            }
        }

        // The daily rebuilder scans all feedback for the workspace/date. Run it
        // once per server/date job instead of once per rotated CSV. Keep source
        // files in process until derived data is current; a retry can replay
        // duplicate events and safely rebuild before attempting the archive.
        if ($hasImportableRows) {
            $this->importer->rebuildDerivedData((string) $server->workspace_id, $accountingDate, array_keys($affectedListIds));
        }

        foreach ($archiveCandidates as $candidate) {
            $archiveDirectory = $candidate['base'].'/archive/'.$accountingDate;
            if (! $session->createDirectory($archiveDirectory)) {
                throw new RuntimeException('The PMTA feedback archive directory could not be created.');
            }

            $archiveFiles = $session->listDirectory($archiveDirectory);
            if ($archiveFiles === false) {
                throw new RuntimeException('The PMTA feedback archive could not be listed.');
            }

            $filename = basename($candidate['source']);
            $archivePath = $this->availableArchivePath($archiveDirectory, $filename, $archiveFiles);
            if (! $session->moveFile($candidate['source'], $archivePath)) {
                throw new RuntimeException('An imported PMTA feedback file could not be archived.');
            }

            $counts['archived']++;
        }

        return $counts;
    }

    /** @param list<string>|false $entries
     * @return list<string>
     */
    private function matchingFiles(array|false $entries, string $prefix, string $date): array
    {
        if ($entries === false) {
            throw new RuntimeException('A PMTA feedback directory could not be read.');
        }

        $pattern = '/^'.preg_quote($prefix.'-'.$date.'-', '/').'.*\.csv$/D';
        $files = [];
        foreach ($entries as $entry) {
            if ($entry !== basename($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match($pattern, $entry) === 1) {
                $files[] = $entry;
            }
        }

        sort($files, SORT_STRING);

        return array_values(array_unique($files));
    }

    private function stageRemoteFile(DmsInstallerSshSession $session, string $remotePath, int $sizeBefore, int $modifiedBefore): string
    {
        $directory = (string) config('infrastructure.pmta_feedback_staging_path', storage_path('app/private/pmta-feedback-staging'));
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Private PMTA feedback staging is unavailable.');
        }
        if (! chmod($directory, 0700)) {
            throw new RuntimeException('Private PMTA feedback staging is unavailable.');
        }
        $directoryMode = fileperms($directory);
        if ($directoryMode === false || ($directoryMode & 0077) !== 0) {
            throw new RuntimeException('Private PMTA feedback staging permissions are not restrictive.');
        }

        $localPath = tempnam($directory, 'feedback-');
        if ($localPath === false) {
            throw new RuntimeException('A private PMTA feedback staging file could not be created.');
        }
        if (! chmod($localPath, 0600)) {
            unlink($localPath);
            throw new RuntimeException('Private PMTA feedback staging is unavailable.');
        }
        $fileMode = fileperms($localPath);
        if ($fileMode === false || ($fileMode & 0077) !== 0) {
            unlink($localPath);
            throw new RuntimeException('Private PMTA feedback staging permissions are not restrictive.');
        }

        try {
            if (! $session->downloadFile($remotePath, $localPath)) {
                throw new RuntimeException('A PMTA feedback file could not be copied from the selected server.');
            }

            $sizeAfter = $session->fileSize($remotePath);
            $modifiedAfter = $session->fileModifiedAt($remotePath);
            $localSize = filesize($localPath);
            if ($sizeAfter !== $sizeBefore || $modifiedAfter !== $modifiedBefore || $localSize !== $sizeBefore) {
                throw new RuntimeException('A PMTA feedback file changed while it was being copied.');
            }

            return $localPath;
        } catch (\Throwable $exception) {
            if (is_file($localPath)) {
                unlink($localPath);
            }

            throw $exception;
        }
    }

    /** @param list<string> $archiveFiles */
    private function availableArchivePath(string $directory, string $filename, array $archiveFiles): string
    {
        $occupied = array_fill_keys($archiveFiles, true);
        $base = $filename.'_'.now()->timestamp;
        $candidate = $base;
        $suffix = 1;
        while (isset($occupied[$candidate])) {
            $candidate = $base.'_'.$suffix++;
        }

        return $directory.'/'.$candidate;
    }
}
