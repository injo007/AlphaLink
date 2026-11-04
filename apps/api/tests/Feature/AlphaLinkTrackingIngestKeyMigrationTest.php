<?php

namespace Tests\Feature;

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AlphaLinkTrackingIngestKeyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_ingest_key_rename_is_reversible_and_preserves_encrypted_values(): void
    {
        $workspace = Workspace::create([
            'name' => 'Tracking migration workspace',
            'slug' => 'tracking-migration-workspace',
        ]);
        $ciphertext = Crypt::encryptString('synthetic-tracking-ingest-key');
        DB::table('workspace_infrastructure_settings')->insert([
            'workspace_id' => $workspace->getKey(),
            'tracking_ingest_key' => $ciphertext,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_11_04_000000_rename_tracking_ingest_key_on_workspace_infrastructure_settings_table.php');

        $migration->down();

        $this->assertTrue(Schema::hasColumn('workspace_infrastructure_settings', 'mini_app_key'));
        $this->assertFalse(Schema::hasColumn('workspace_infrastructure_settings', 'tracking_ingest_key'));
        $this->assertSame($ciphertext, DB::table('workspace_infrastructure_settings')->value('mini_app_key'));

        $migration->up();

        $this->assertFalse(Schema::hasColumn('workspace_infrastructure_settings', 'mini_app_key'));
        $this->assertTrue(Schema::hasColumn('workspace_infrastructure_settings', 'tracking_ingest_key'));
        $storedCiphertext = DB::table('workspace_infrastructure_settings')->value('tracking_ingest_key');
        $this->assertSame($ciphertext, $storedCiphertext);
        $this->assertSame('synthetic-tracking-ingest-key', Crypt::decryptString($storedCiphertext));
    }
}
