<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_list_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('audience_list_id');
            $table->date('at');
            $table->unsignedInteger('fresh')->default(0);
            $table->unsignedInteger('delivery')->default(0);
            $table->unsignedInteger('open')->default(0);
            $table->unsignedInteger('click')->default(0);
            $table->unsignedInteger('lead')->default(0);
            $table->unsignedInteger('unsubscribe')->default(0);
            $table->timestampsTz();

            $table->unique(['workspace_id', 'audience_list_id', 'at'], 'alh_workspace_list_date_uq');
            $table->foreign(['workspace_id', 'audience_list_id'], 'alh_workspace_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->cascadeOnDelete();
            $table->index(['workspace_id', 'audience_list_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_list_histories');
    }
};
