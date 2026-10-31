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
        $queueConfig = file_get_contents(base_path('config/queue.php'));

        $this->assertIsString($installer);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $installer);
        $this->assertStringContainsString('FILESYSTEM_DISK=local', $installer);
        $this->assertStringContainsString('QUEUE_CONNECTION=redis', $installer);
        $this->assertStringContainsString('"$SHARED_ROOT/storage/app/private"', $installer);
        $this->assertStringContainsString('ln -s "$SHARED_ROOT/storage" "$RELEASE_API_ROOT/storage"', $installer);
        $this->assertStringContainsString('queue:work drop_suppression --queue=drop-suppression', $installer);
        $this->assertStringContainsString('systemctl enable --now alphalink-horizon.service alphalink-drop-suppression.service', $installer);
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertIsString($queueConfig);
        $this->assertStringContainsString("env('DROP_SUPPRESSION_QUEUE_DRIVER', 'database')", $queueConfig);
        $this->assertSame('sync', config('queue.connections.drop_suppression.driver'));
        $this->assertSame('drop-suppression', config('queue.connections.drop_suppression.queue'));
    }

    public function test_fresh_install_and_deploy_enable_and_verify_server_reinstall_execution(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $this->assertStringContainsString('ALPHALINK_REINSTALL_EXECUTION_ENABLED=true', $installer);
        $this->assertStringContainsString('reinstall_written = 0', $installer);
        $this->assertStringContainsString('^ALPHALINK_REINSTALL_EXECUTION_ENABLED=', $installer);
        $this->assertStringContainsString('config:show infrastructure.reinstall_execution_enabled', $installer);
        $this->assertStringContainsString('Laravel did not load server reinstall execution as enabled.', $installer);
    }

    public function test_frontend_build_uses_valid_arithmetic_and_retries_with_low_memory_settings(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');
        $viteConfig = file_get_contents(base_path('vite.config.js'));
        $tailwindCss = file_get_contents(base_path('resources/css/app.css'));

        $this->assertIsString($installer);
        $this->assertIsString($viteConfig);
        $this->assertIsString($tailwindCss);
        $this->assertStringContainsString('free_kib < 2621440', $installer);
        $this->assertStringNotContainsString('free_kib < 2_621_440', $installer);
        $this->assertStringContainsString('host_memory_mib * 60 / 100', $installer);
        $this->assertStringContainsString('node_heap_mib >= 512', $installer);
        $this->assertStringContainsString('node_heap_mib <= 2048', $installer);
        $this->assertStringContainsString('ALPHALINK_LOW_MEMORY_BUILD=1', $installer);
        $this->assertStringContainsString('ROLLDOWN_WORKER_THREADS=1 RAYON_NUM_THREADS=1', $installer);
        $this->assertStringContainsString("process.env.ALPHALINK_LOW_MEMORY_BUILD === '1' ? false : 'oxc'", $viteConfig);
        $this->assertStringContainsString("@import 'tailwindcss' source(none);", $tailwindCss);
        $this->assertStringContainsString("@source '../';", $tailwindCss);
        $this->assertStringNotContainsString("@source '../../storage/framework/views/", $tailwindCss);
    }

    public function test_deploy_prunes_expired_artifacts_and_preflights_database_backup_before_downtime(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $cleanupPosition = strpos($installer, "info 'Pruning expired daily backups and releases outside the rollback window...'");
        $capacityPosition = strpos($installer, 'SELECT pg_database_size(\'${DB_NAME}\')');
        $maintenancePosition = strpos($installer, "info 'Entering maintenance mode and pausing background work...'");

        $this->assertIsInt($cleanupPosition);
        $this->assertIsInt($capacityPosition);
        $this->assertIsInt($maintenancePosition);
        $this->assertLessThan($maintenancePosition, $cleanupPosition);
        $this->assertLessThan($maintenancePosition, $capacityPosition);
        $this->assertStringContainsString("-name 'alphalink-*.dump' -mtime +14 -delete", $installer);
        $this->assertStringContainsString('ExecStartPre=/usr/bin/find /var/backups/alphalink', $installer);
        $this->assertStringContainsString('database needs at least ${required_backup_mib} MiB', $installer);
        $this->assertStringContainsString('backup_staging_file="${backup_file}.partial-$$"', $installer);
        $this->assertStringContainsString('if (( backup_verified == 0 ))', $installer);
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
