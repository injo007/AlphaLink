<?php

namespace Tests\Feature;

use App\Services\Infrastructure\AlphaLinkReviewedAssetDigestVerifier;
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

    public function test_fresh_installer_defaults_to_the_current_alphalink_master_branch(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $this->assertStringContainsString('REPOSITORY_INPUT="${GITHUB_REPO:-injo007/AlphaLink}"', $installer);
        $this->assertStringContainsString('BRANCH="${GITHUB_BRANCH:-master}"', $installer);
        $this->assertStringContainsString('git -c credential.helper= clone --branch "$branch" --single-branch "$repository" "$destination"', $installer);
        $this->assertStringContainsString('RELEASE_SHA="$(git -C "$SOURCE_STAGING_DIR" rev-parse HEAD)"', $installer);
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
        $cleanupPosition = strpos($installer, "info 'Pruning expired daily backups and releases outside the rollback window before staging...'");
        $extractPosition = strpos($installer, 'git -C "$SOURCE_ROOT" archive --format=tar "$release_sha" -- . ');
        $capacityPosition = strpos($installer, 'SELECT pg_database_size(\'${DB_NAME}\')');
        $maintenancePosition = strpos($installer, "info 'Entering maintenance mode and pausing background work...'");

        $this->assertIsInt($cleanupPosition);
        $this->assertIsInt($extractPosition);
        $this->assertIsInt($capacityPosition);
        $this->assertIsInt($maintenancePosition);
        $this->assertLessThan($extractPosition, $cleanupPosition);
        $this->assertLessThan($maintenancePosition, $cleanupPosition);
        $this->assertLessThan($maintenancePosition, $capacityPosition);
        $this->assertStringContainsString("-name 'alphalink-*.dump' -mtime +14 -delete", $installer);
        $this->assertStringContainsString("':(exclude)install-assets/**'", $installer);
        $this->assertStringContainsString('df -Pi "$RELEASES_ROOT"', $installer);
        $this->assertStringContainsString('Deployment stopped before release extraction:', $installer);
        $this->assertStringContainsString('ExecStartPre=/usr/bin/find /var/backups/alphalink', $installer);
        $this->assertStringContainsString('database needs at least ${required_backup_mib} MiB', $installer);
        $this->assertStringContainsString('backup_staging_file="${backup_file}.partial-$$"', $installer);
        $this->assertStringContainsString('if (( backup_verified == 0 ))', $installer);
    }

    public function test_shared_storage_permission_sweep_skips_live_private_feedback_files(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $this->assertStringContainsString('local pmta_feedback_staging_root="${SHARED_ROOT}/storage/app/private/pmta-feedback-staging"', $installer);
        $this->assertStringContainsString('-path "$pmta_feedback_staging_root" \\) -prune -o -exec chown -h www-data:www-data {} +', $installer);
        $this->assertStringContainsString('chown www-data:www-data "$pmta_feedback_staging_root"', $installer);
        $this->assertStringContainsString('chmod 0700 "$pmta_feedback_staging_root"', $installer);
        $this->assertStringNotContainsString('chown -R www-data:www-data "${roots[@]}"', $installer);
    }

    public function test_deploy_and_resume_normalize_only_the_inactive_bootstrap_cache(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $this->assertStringContainsString('normalize_release_bootstrap_cache_permissions "$temporary_release/apps/api/bootstrap/cache"', $installer);
        $this->assertStringContainsString('normalize_release_bootstrap_cache_permissions "$staging_path/apps/api/bootstrap/cache"', $installer);
        $this->assertStringNotContainsString('normalize_shared_storage_permissions "$temporary_release/apps/api/bootstrap/cache"', $installer);
        $this->assertStringNotContainsString('normalize_shared_storage_permissions "$staging_path/apps/api/bootstrap/cache"', $installer);
        $this->assertStringContainsString("normalize_shared_storage_permissions\ninstall -d -o www-data -g www-data -m 0700", $installer);
    }

    public function test_private_runtime_directories_keep_owner_only_modes_across_install_deploy_and_resume(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $ownerOnlyPaths = [
            '"$SHARED_ROOT/storage/app/private"',
            '"$SHARED_ROOT/storage/framework/uploads"',
            '"$SHARED_ROOT/storage/framework/tmp"',
            '"$SHARED_ROOT/storage/framework/nginx-body"',
        ];

        $this->assertStringContainsString('install -d -o www-data -g www-data -m 0700 "$SHARED_ROOT/storage/app/private"', $installer);
        $this->assertStringContainsString("install -d -o www-data -g www-data -m 0700 \\\n            \"\$SHARED_ROOT/storage/app/private\" \\\n            \"\$SHARED_ROOT/storage/framework/uploads\" \"\$SHARED_ROOT/storage/framework/tmp\" \\\n            \"\$SHARED_ROOT/storage/framework/nginx-body\"", $installer);

        foreach ($ownerOnlyPaths as $path) {
            $this->assertStringNotContainsString($path, $this->permissionInstallBlock($installer, '2770'));
        }

        $sharedModeBlock = $this->permissionInstallBlock($installer, '2770');
        $this->assertStringContainsString('"$SHARED_ROOT/storage/app/public"', $sharedModeBlock);
        $this->assertStringContainsString('"$SHARED_ROOT/storage/framework/cache/data"', $sharedModeBlock);
        $this->assertStringContainsString('"$SHARED_ROOT/storage/framework/sessions"', $sharedModeBlock);
        $this->assertStringContainsString('"$SHARED_ROOT/storage/framework/views"', $sharedModeBlock);
        $this->assertStringContainsString('"$SHARED_ROOT/storage/logs"', $sharedModeBlock);
    }

    private function permissionInstallBlock(string $installer, string $mode): string
    {
        $blocks = [];
        $lines = explode("\n", $installer);

        for ($index = 0, $lineCount = count($lines); $index < $lineCount; $index++) {
            if (! str_contains($lines[$index], 'install -d -o www-data -g www-data -m '.$mode)) {
                continue;
            }

            $block = [$lines[$index]];
            while (str_ends_with(rtrim($lines[$index]), '\\') && $index + 1 < $lineCount) {
                $block[] = $lines[++$index];
            }
            $blocks[] = implode("\n", $block);
        }

        return implode("\n", $blocks);
    }

    public function test_repository_install_bundle_contains_every_reviewed_nonsecret_asset(): void
    {
        $assetRoot = dirname(base_path(), 2).'/install-assets';
        $digests = AlphaLinkReviewedAssetDigestVerifier::REVIEWED_DIGESTS;

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
        $this->assertFileExists($assetRoot.'/optional/tracking/index.php');
        $this->assertFileExists($assetRoot.'/optional/tracking/TrackerRequestHandler.php');
        $this->assertSame(
            ['size' => 3250, 'sha256' => '8fcc32fca7d97d3144715ddd9e676bcfc0cbfb7107d61300669d002039029974'],
            $digests['optional/tracking/index.php'],
        );
        $this->assertSame(
            ['size' => 11669, 'sha256' => 'c4a586dcea351051775e733bc092de5a715c48f4cd0d12db93119bbc696c65bb'],
            $digests['optional/tracking/TrackerRequestHandler.php'],
        );
    }

    public function test_deploy_repairs_only_missing_reviewed_tracking_assets_in_a_nonempty_private_bundle(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $this->assertStringContainsString('repair_missing_reviewed_tracking_assets "$source"', $installer);
        $this->assertStringContainsString('repair_missing_reviewed_tracking_assets_from_git()', $installer);
        $this->assertStringContainsString('git -C "$repository_root" cat-file blob "$revision:install-assets/$relative_path"', $installer);
        $this->assertStringContainsString('stage_repository_install_assets_if_empty "$SOURCE_ROOT/install-assets" "$release_sha"', $installer);
        $this->assertStringContainsString('local source="$1" reviewed_revision="${2:-}"', $installer);
        $this->assertStringContainsString('repository_root="$SOURCE_ROOT"', $installer);
        $this->assertStringContainsString('repair_missing_reviewed_tracking_assets_from_git "$repository_root" "$reviewed_revision"', $installer);
        $this->assertStringContainsString('if (( source_available )); then', $installer);
        $this->assertStringContainsString('if (( ! source_available )); then', $installer);
        $this->assertStringContainsString("repair_missing_reviewed_tracking_asset \"\$source_root\" 'optional/tracking/index.php' 3250 '8fcc32fca7d97d3144715ddd9e676bcfc0cbfb7107d61300669d002039029974'", $installer);
        $this->assertStringContainsString("repair_missing_reviewed_tracking_asset \"\$source_root\" 'optional/tracking/TrackerRequestHandler.php' 11669 'c4a586dcea351051775e733bc092de5a715c48f4cd0d12db93119bbc696c65bb'", $installer);
        $this->assertStringContainsString('if [[ -e "$destination_file" || -L "$destination_file" ]]; then', $installer);
        $this->assertStringContainsString('Preserving existing private tracking asset for review:', $installer);
        $this->assertStringContainsString('install -o www-data -g www-data -m 0600 -- "$source_file" "$temporary_file"', $installer);
        $this->assertStringContainsString('The staged private tracking asset failed its reviewed digest check:', $installer);
        $this->assertStringContainsString("info 'Preserving the existing private installation asset bundle.'", $installer);
    }

    public function test_deployment_health_check_requires_sign_in_to_be_live_and_maintenance_to_be_cleared(): void
    {
        $repositoryRoot = dirname(base_path(), 2);
        $installer = file_get_contents($repositoryRoot.'/install-ubuntu-26.sh');

        $this->assertIsString($installer);
        $this->assertStringContainsString('verify_local_https_application_health "$app_domain"', $installer);
        $this->assertStringContainsString('verify_local_https_application_health "$rollback_domain"', $installer);
        $this->assertStringContainsString('"https://${domain}/sign-in"', $installer);
        $this->assertStringContainsString('[[ ! -f "$SHARED_ROOT/storage/framework/maintenance.php" ]]', $installer);
    }
}
