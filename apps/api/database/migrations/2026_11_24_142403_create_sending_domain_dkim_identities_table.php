<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sending_domain_dkim_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('sending_domain_id');
            $table->string('hostname', 253);
            $table->string('selector', 63);
            $table->longText('private_key');
            $table->text('public_record');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'hostname'], 'sending_dkim_hostname_unique');
            $table->foreign(['workspace_id', 'sending_domain_id'], 'sending_dkim_domain_scope_fk')
                ->references(['workspace_id', 'id'])->on('sending_domains')->restrictOnDelete();
        });

        Schema::table('managed_server_ip_domains', function (Blueprint $table): void {
            $table->foreignId('sending_domain_dkim_identity_id')->nullable();
            $table->foreign(['workspace_id', 'sending_domain_dkim_identity_id'], 'server_ip_domain_dkim_scope_fk')
                ->references(['workspace_id', 'id'])->on('sending_domain_dkim_identities')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('managed_server_ip_domains', function (Blueprint $table): void {
            $table->dropForeign('server_ip_domain_dkim_scope_fk');
            $table->dropColumn('sending_domain_dkim_identity_id');
        });
        Schema::dropIfExists('sending_domain_dkim_identities');
    }
};
