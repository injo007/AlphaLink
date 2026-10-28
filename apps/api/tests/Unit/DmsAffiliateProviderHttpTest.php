<?php

namespace Tests\Unit;

use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DmsAffiliateProviderHttpTest extends TestCase
{
    public function test_bounded_https_download_reads_a_fake_response(): void
    {
        Http::fake(['https://cdn.example.test/suppression.txt' => Http::response("BLOCKED@example.test\nblocked@example.test\n", 200)]);
        $client = new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']);
        $this->assertSame("BLOCKED@example.test\nblocked@example.test\n", $client->getBytesFollowingPublicRedirects('https://cdn.example.test/suppression.txt', [], 1000));
    }

    public function test_streamed_download_writes_to_disk_and_reports_progress_without_returning_the_body(): void
    {
        Http::fake(['https://cdn.example.test/large-suppression.txt' => Http::response(str_repeat("blocked@example.test\n", 100), 200)]);
        $path = tempnam(sys_get_temp_dir(), 'alphalink-http-suppression-test-');
        $progress = [];

        try {
            $bytes = (new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']))
                ->downloadToFileFollowingPublicRedirects(
                    'https://cdn.example.test/large-suppression.txt',
                    $path,
                    [],
                    function (int $downloaded, ?int $total) use (&$progress): void {
                        $progress[] = [$downloaded, $total];
                    },
                );

            $this->assertSame(filesize($path), $bytes);
            $this->assertSame(str_repeat("blocked@example.test\n", 100), file_get_contents($path));
            $this->assertNotEmpty($progress);
            $this->assertSame($bytes, end($progress)[0]);
        } finally {
            @unlink($path);
        }
    }

    public function test_streamed_download_revalidates_public_https_redirect_targets(): void
    {
        Http::fake([
            'https://cdn.example.test/suppression.txt' => Http::response('', 302, ['Location' => 'https://files.example.test/suppression.txt']),
            'https://files.example.test/suppression.txt' => Http::response("blocked@example.test\n", 200),
        ]);
        $resolvedHosts = [];
        $path = tempnam(sys_get_temp_dir(), 'alphalink-http-redirect-test-');

        try {
            $bytes = (new DmsAffiliateProviderHttp(static function (string $host) use (&$resolvedHosts): array {
                $resolvedHosts[] = $host;

                return ['1.1.1.1'];
            }))->downloadToFileFollowingPublicRedirects('https://cdn.example.test/suppression.txt', $path);

            $this->assertSame(strlen("blocked@example.test\n"), $bytes);
            $this->assertSame("blocked@example.test\n", file_get_contents($path));
            $this->assertSame(['cdn.example.test', 'files.example.test'], $resolvedHosts);
            Http::assertSentCount(2);
        } finally {
            @unlink($path);
        }
    }
}
