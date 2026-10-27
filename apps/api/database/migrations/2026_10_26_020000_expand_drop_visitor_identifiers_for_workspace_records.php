<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_drop_visitors', function (Blueprint $table): void {
            foreach (['server_id', 'ip_id', 'domain_id', 'from_id', 'subject_id', 'creative_id', 'list_id', 'email_id'] as $column) {
                $table->dropIndex([$column]);
            }

            foreach (['server_id', 'ip_id', 'domain_id', 'from_id', 'subject_id', 'creative_id', 'list_id'] as $column) {
                $table->string($column, 64)->nullable()->change();
            }
            $table->string('email_id', 64)->change();

            foreach (['server_id', 'ip_id', 'domain_id', 'from_id', 'subject_id', 'creative_id', 'list_id', 'email_id'] as $column) {
                $table->index($column);
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspace_drop_visitors', function (Blueprint $table): void {
            foreach (['server_id', 'ip_id', 'domain_id', 'from_id', 'subject_id', 'creative_id', 'list_id', 'email_id'] as $column) {
                $table->dropIndex([$column]);
            }

            foreach (['server_id', 'ip_id', 'domain_id', 'from_id', 'subject_id', 'creative_id', 'list_id'] as $column) {
                $table->unsignedBigInteger($column)->nullable()->change();
            }
            $table->unsignedBigInteger('email_id')->change();

            foreach (['server_id', 'ip_id', 'domain_id', 'from_id', 'subject_id', 'creative_id', 'list_id', 'email_id'] as $column) {
                $table->index($column);
            }
        });
    }
};
