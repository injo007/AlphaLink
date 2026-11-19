<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->string('niche', 160)->nullable();
        });

        Schema::table('audience_list_import_batches', function (Blueprint $table): void {
            $table->foreignUuid('duplicate_audience_list_id')->nullable();
            $table->string('duplicate_niche', 160)->nullable();
            $table->foreign(['workspace_id', 'duplicate_audience_list_id'], 'aud_import_workspace_duplicate_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audience_list_import_batches', function (Blueprint $table): void {
            $table->dropForeign('aud_import_workspace_duplicate_list_fk');
            $table->dropColumn(['duplicate_audience_list_id', 'duplicate_niche']);
        });

        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->dropColumn('niche');
        });
    }
};
