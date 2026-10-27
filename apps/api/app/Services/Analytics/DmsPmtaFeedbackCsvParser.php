<?php

namespace App\Services\Analytics;

use Generator;
use InvalidArgumentException;

/** Parse the DMS PMTA accounting columns without shell splitting or exposing recipient addresses. */
class DmsPmtaFeedbackCsvParser
{
    private const HARD_BOUNCE_CATEGORIES = [
        'bad-mailbox', 'bad-domain', 'quota-issues', 'inactive-mailbox',
    ];

    /**
     * @param  resource  $stream
     * @return Generator<int, array{record_key: string, metric: string, category: ?string, source_id: string, snapshot_id: string, recipient_id: string, list_type: string, list_id: string, isp_id: string, server_id: string, ip_id: string, domain_id: string, from_id: string, subject_id: string, creative_id: string, type: string}>
     */
    public function parseStream($stream, string $kind, string $sourceFileId): Generator
    {
        if (! is_resource($stream) || ! in_array($kind, ['delivery', 'bounce'], true) || $sourceFileId === '') {
            throw new InvalidArgumentException('A PMTA CSV stream, kind and stable source file ID are required.');
        }

        $rowNumber = 0;
        while (($columns = fgetcsv($stream, escape: '')) !== false) {
            $rowNumber++;
            $token = trim((string) ($columns[$kind === 'delivery' ? 3 : 4] ?? ''));
            $parts = explode('-', $token);
            if (count($parts) !== 14 || $parts[0] === '0' || in_array($parts[13], ['t', 'q'], true)) {
                continue;
            }

            $category = $kind === 'bounce' ? trim((string) ($columns[1] ?? '')) : null;
            $metric = $kind === 'delivery'
                ? 'deliveries'
                : (in_array($category, self::HARD_BOUNCE_CATEGORIES, true) ? 'hard_bounces' : 'soft_bounces');

            yield [
                'record_key' => hash('sha256', $sourceFileId."\0".$rowNumber."\0".json_encode($columns, JSON_THROW_ON_ERROR)),
                'metric' => $metric,
                'category' => $category,
                'source_id' => $parts[0],
                'snapshot_id' => $parts[1],
                'recipient_id' => $parts[2],
                'list_type' => $parts[3],
                'list_id' => $parts[4],
                'isp_id' => $parts[5],
                'server_id' => $parts[6],
                'ip_id' => $parts[7],
                'domain_id' => $parts[8],
                'from_id' => $parts[9],
                'subject_id' => $parts[10],
                'creative_id' => $parts[11],
                'type' => $parts[13],
            ];
        }
    }
}
