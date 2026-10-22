<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const IMMUTABLE_TABLES = [
        'consent_records',
        'suppression_events',
        'contact_list_membership_events',
        'campaign_revisions',
        'audit_events',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION alphalink_reject_immutable_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only', TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;
            SQL);

        foreach (self::IMMUTABLE_TABLES as $table) {
            DB::unprepared("CREATE TRIGGER alphalink_prevent_mutation BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION alphalink_reject_immutable_mutation()");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::IMMUTABLE_TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS alphalink_prevent_mutation ON {$table}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS alphalink_reject_immutable_mutation()');
    }
};
