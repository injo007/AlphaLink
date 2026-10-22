<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->timestampsTz();
            $table->index('name');
        });

        Schema::table('managed_servers', function (Blueprint $table) {
            $table->foreignId('servers_provider_id')->nullable()->constrained('server_providers')->restrictOnDelete();
            $table->date('expire_at')->nullable();
            $table->string('login_type', 16)->default('password');
            $table->softDeletesTz();
        });

        foreach (DB::table('managed_servers')->select('provider')->distinct()->get() as $row) {
            $providerId = DB::table('server_providers')->insertGetId([
                'name' => (string) $row->provider,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('managed_servers')->where('provider', $row->provider)
                ->update(['servers_provider_id' => $providerId]);
        }

        DB::table('server_credentials')->where('credential_type', 'private_key')
            ->update(['credential_type' => 'ssh_key']);
    }

    public function down(): void
    {
        Schema::table('managed_servers', function (Blueprint $table) {
            $table->dropForeign(['servers_provider_id']);
            $table->dropColumn(['servers_provider_id', 'expire_at', 'login_type', 'deleted_at']);
        });

        Schema::dropIfExists('server_providers');
    }
};
