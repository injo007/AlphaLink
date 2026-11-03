<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
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

    public function up(): void
    {
        $connection = $this->getConnection();

        if (! Schema::connection($connection)->hasTable('migrations')) {
            return;
        }

        $database = DB::connection($connection);
        $database->transaction(function () use ($database): void {
            foreach (self::LEGACY_MIGRATIONS as $legacyName => $alphaLinkName) {
                $legacyCount = $database->table('migrations')->where('migration', $legacyName)->count();
                $alphaLinkCount = $database->table('migrations')->where('migration', $alphaLinkName)->count();

                if ($legacyCount > 1 || $alphaLinkCount > 1 || ($legacyCount === 1 && $alphaLinkCount === 1)) {
                    throw new RuntimeException(
                        "Cannot normalize migration ledger entry [{$legacyName}]: source or destination is ambiguous."
                    );
                }

                if ($legacyCount === 1) {
                    $database->table('migrations')
                        ->where('migration', $legacyName)
                        ->update(['migration' => $alphaLinkName]);
                }
            }
        });
    }

    public function down(): void
    {
        // The ledger normalization records historical work under its current migration name.
    }
};
