<?php

namespace Tests\Unit;

use App\Services\Analytics\DmsPmtaFeedbackCsvParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DmsPmtaFeedbackCsvParserTest extends TestCase
{
    public function test_delivery_columns_and_quote_aware_csv_preserve_dimensions(): void
    {
        $token = $this->token('c');
        $rows = $this->parse('delivery', [
            ['2026-10-27', 'ok', 'recipient,with-comma@example.test', $token],
            ['2026-10-27', 'ok', 'recipient@example.test', $this->token('t')],
            ['2026-10-27', 'ok', 'recipient@example.test', $this->token('q')],
            ['2026-10-27', 'ok', 'recipient@example.test', '0-'.substr($token, strlen('campaign-'))],
            ['2026-10-27', 'ok', 'recipient@example.test', 'broken'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('deliveries', $rows[0]['metric']);
        $this->assertSame('campaign-id', $rows[0]['source_id'].'-'.$rows[0]['snapshot_id']);
        $this->assertSame('contact', $rows[0]['recipient_id']);
        $this->assertSame('list', $rows[0]['list_id']);
        $this->assertSame('c', $rows[0]['type']);
        $this->assertArrayNotHasKey('email', $rows[0]);
    }

    public function test_bounce_categories_follow_dms_hard_and_soft_rules(): void
    {
        $rows = [];
        foreach (['bad-mailbox', 'bad-domain', 'quota-issues', 'inactive-mailbox', 'policy-rejection'] as $category) {
            $rows[] = ['2026-10-27', $category, 'ignored', 'ignored', $this->token('d')];
        }
        $parsed = $this->parse('bounce', $rows);

        $this->assertSame(
            ['hard_bounces', 'hard_bounces', 'hard_bounces', 'hard_bounces', 'soft_bounces'],
            array_column($parsed, 'metric'),
        );
        $this->assertSame('policy-rejection', $parsed[4]['category']);
        $this->assertSame('d', $parsed[4]['type']);
    }

    public function test_record_identity_is_stable_for_a_replay_and_distinct_by_file_and_row(): void
    {
        $row = ['date', 'ok', 'recipient@example.test', $this->token('c')];
        $first = $this->parse('delivery', [$row, $row], 'd-2026-10-27-1.csv');
        $replayed = $this->parse('delivery', [$row, $row], 'd-2026-10-27-1.csv');
        $otherFile = $this->parse('delivery', [$row], 'd-2026-10-27-2.csv');

        $this->assertSame($first, $replayed);
        $this->assertNotSame($first[0]['record_key'], $first[1]['record_key']);
        $this->assertNotSame($first[0]['record_key'], $otherFile[0]['record_key']);
    }

    public function test_invalid_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parse('other', []);
    }

    private function token(string $type): string
    {
        return implode('-', [
            'campaign', 'id', 'contact', 'fresh', 'list', 'isp', 'server',
            'ip', 'domain', 'from', 'subject', 'creative', 'app', $type,
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<array<string, string|null>>
     */
    private function parse(string $kind, array $rows, string $sourceFileId = 'file.csv'): array
    {
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '');
        }
        rewind($stream);

        try {
            return iterator_to_array((new DmsPmtaFeedbackCsvParser)->parseStream($stream, $kind, $sourceFileId), false);
        } finally {
            fclose($stream);
        }
    }
}
