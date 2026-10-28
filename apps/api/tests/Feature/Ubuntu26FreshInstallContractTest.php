<?php

namespace Tests\Feature;

use App\Services\Infrastructure\DmsReviewedAssetDigestVerifier;
use Tests\TestCase;

class Ubuntu26FreshInstallContractTest extends TestCase
{
    public function test_fresh_installer_sets_up_postgres_persistent_private_storage_and_suppression_worker(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $installer);
        $this->assertStringContainsString('FILESYSTEM_DISK=local', $installer);
        $this->assertStringContainsString('QUEUE_CONNECTION=redis', $installer);
        $this->assertStringContainsString('"$SHARED_ROOT/storage/app/private"', $installer);
        $this->assertStringContainsString('ln -s "$SHARED_ROOT/storage" "$RELEASE_API_ROOT/storage"', $installer);
        $this->assertStringContainsString('queue:work drop_suppression --queue=drop-suppression', $installer);
        $this->assertStringContainsString('systemctl enable --now alphalink-horizon.service alphalink-drop-suppression.service', $installer);
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertSame('database', config('queue.connections.drop_suppression.driver'));
        $this->assertSame('drop-suppression', config('queue.connections.drop_suppression.queue'));
    }

    public function test_repository_install_bundle_contains_every_reviewed_nonsecret_asset(): void
    {
        $assetRoot = dirname(base_path(), 2).'/install-assets';
        $digests = DmsReviewedAssetDigestVerifier::REVIEWED_DIGESTS;

        foreach ($digests as $relativePath => $expected) {
            if ($relativePath === 'optional/dkim/keys.json') {
                continue;
            }

            $path = $assetRoot.'/'.$relativePath;
            $this->assertFileExists($path, "Missing reviewed installation asset: {$relativePath}");
            $this->assertSame($expected['size'], filesize($path), "Unexpected size for installation asset: {$relativePath}");
            $this->assertSame($expected['sha256'], hash_file('sha256', $path), "Unexpected digest for installation asset: {$relativePath}");
        }

        $this->assertFileDoesNotExist($assetRoot.'/optional/dkim/keys.json');
        $this->assertFileExists($assetRoot.'/optional/reverse-proxy/nginx.conf');
        $this->assertFileExists($assetRoot.'/optional/tracking/mini-app.zip');
    }
}
