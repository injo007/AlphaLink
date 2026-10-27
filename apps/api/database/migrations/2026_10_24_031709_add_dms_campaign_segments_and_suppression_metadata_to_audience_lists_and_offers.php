<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->string('email_type', 24)->default('fresh');
            $table->index(['workspace_id', 'audience_list_id', 'email_type', 'status'], 'clm_campaign_segment_idx');
        });

        Schema::table('workspace_offers', function (Blueprint $table): void {
            $table->char('suppression_sha256', 64)->nullable();
            $table->string('suppression_format', 16)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_offers', function (Blueprint $table): void {
            $table->dropColumn(['suppression_sha256', 'suppression_format']);
        });

        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->dropIndex('clm_campaign_segment_idx');
            $table->dropColumn('email_type');
        });
    }
};
