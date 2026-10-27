<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sending_domains', function (Blueprint $table) {
            $table->string('provider_domain_id')->nullable();
            $table->boolean('have_who_is')->nullable();
            $table->boolean('have_renew')->nullable();
            $table->date('expire_at')->nullable();
            $table->unique(['workspace_id', 'provider_domain_id'], 'sending_domains_provider_id_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sending_domains', function (Blueprint $table) {
            $table->dropUnique('sending_domains_provider_id_scope_unique');
            $table->dropColumn(['provider_domain_id', 'have_who_is', 'have_renew', 'expire_at']);
        });
    }
};
