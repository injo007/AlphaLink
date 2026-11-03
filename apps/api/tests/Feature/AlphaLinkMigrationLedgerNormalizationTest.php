<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AlphaLinkMigrationLedgerNormalizationTest extends TestCase
{
    private const LEGACY_MIGRATIONS = [
        '2026_10_22_230000_add_dms_server_registration_fields' => '2026_10_22_230000_add_alphalink_server_registration_fields',
        '2026_10_22_233000_add_dms_bulk_install_eligibility' => '2026_10_22_233000_add_alphalink_bulk_install_eligibility',
        '2026_10_23_015808_add_dms_credentials_and_workspace_scope_to_domain_providers_table' => '2026_10_23_015808_add_alphalink_credentials_and_workspace_scope_to_domain_providers_table',
        '2026_10_23_175625_add_dms_management_fields_to_server_providers_table' => '2026_10_23_175625_add_alphalink_management_fields_to_server_providers_table',
        '2026_10_24_030000_add_dms_isp_linkage_to_audience_lists' => '2026_10_24_030000_add_alphalink_isp_linkage_to_audience_lists',
        '2026_10_24_031706_add_dms_campaign_segments_and_suppression_metadata_to_audience_lists_and_offers' => '2026_10_24_031706_add_alphalink_campaign_segments_and_suppression_metadata_to_audience_lists_and_offers',
        '2026_10_24_031709_add_dms_campaign_segments_and_suppression_metadata_to_audience_lists_and_offers' => '2026_10_24_031709_add_alphalink_campaign_segments_and_suppression_metadata_to_audience_lists_and_offers',
        '2026_10_24_190000_add_dms_list_management_fields' => '2026_10_24_190000_add_alphalink_list_management_fields',
        '2026_10_24_230000_add_unavailable_dms_types_to_audience_list_histories' => '2026_10_24_230000_add_unavailable_alphalink_types_to_audience_list_histories',
        '2026_10_25_225913_add_dms_account_fields_to_users_table' => '2026_10_25_225913_add_alphalink_account_fields_to_users_table',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('migrations');
        Schema::create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }

    public function test_ledger_normalization_preserves_batches_and_renames_every_historical_entry(): void
    {
        foreach (array_keys(self::LEGACY_MIGRATIONS) as $index => $legacyName) {
            DB::table('migrations')->insert([
                'migration' => $legacyName,
                'batch' => $index % 3 + 1,
            ]);
        }

        $this->ledgerMigration()->up();

        $this->assertSame(count(self::LEGACY_MIGRATIONS), DB::table('migrations')->count());

        foreach (array_values(self::LEGACY_MIGRATIONS) as $index => $alphaLinkName) {
            $this->assertDatabaseHas('migrations', [
                'migration' => $alphaLinkName,
                'batch' => $index % 3 + 1,
            ]);
        }

        foreach (array_keys(self::LEGACY_MIGRATIONS) as $legacyName) {
            $this->assertDatabaseMissing('migrations', ['migration' => $legacyName]);
        }
    }

    public function test_ambiguous_ledger_rows_abort_all_renames_atomically(): void
    {
        $names = array_keys(self::LEGACY_MIGRATIONS);
        $targets = array_values(self::LEGACY_MIGRATIONS);

        DB::table('migrations')->insert([
            ['migration' => $names[0], 'batch' => 4],
            ['migration' => $names[1], 'batch' => 5],
            ['migration' => $targets[1], 'batch' => 5],
        ]);

        try {
            $this->ledgerMigration()->up();
            $this->fail('Conflicting historical and normalized rows must not be guessed or deleted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($names[1], $exception->getMessage());
        }

        $this->assertDatabaseHas('migrations', ['migration' => $names[0], 'batch' => 4]);
        $this->assertDatabaseMissing('migrations', ['migration' => $targets[0]]);
        $this->assertDatabaseHas('migrations', ['migration' => $names[1], 'batch' => 5]);
        $this->assertDatabaseHas('migrations', ['migration' => $targets[1], 'batch' => 5]);
    }

    public function test_empty_fresh_ledger_stays_unchanged_and_all_renamed_migrations_can_run(): void
    {
        $this->ledgerMigration()->up();

        $this->assertDatabaseCount('migrations', 0);

        foreach (array_values(self::LEGACY_MIGRATIONS) as $alphaLinkName) {
            $migration = require database_path("migrations/{$alphaLinkName}.php");

            $this->assertTrue($migration->shouldRun(), "{$alphaLinkName} should run on a fresh database.");
        }
    }

    public function test_renamed_migrations_are_skipped_when_the_normalized_ledger_records_them(): void
    {
        foreach (array_values(self::LEGACY_MIGRATIONS) as $index => $alphaLinkName) {
            DB::table('migrations')->insert([
                'migration' => $alphaLinkName,
                'batch' => $index % 3 + 1,
            ]);
        }

        foreach (array_values(self::LEGACY_MIGRATIONS) as $alphaLinkName) {
            $migration = require database_path("migrations/{$alphaLinkName}.php");

            $this->assertFalse($migration->shouldRun(), "{$alphaLinkName} must not execute twice.");
        }
    }

    private function ledgerMigration(): object
    {
        return require database_path('migrations/2026_10_22_225000_normalize_historical_migration_ledger_names.php');
    }
}
