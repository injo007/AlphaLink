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
        Schema::create('workspace_drop_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_drop_id')->constrained('workspace_drops')->cascadeOnDelete();
            $table->string('type')->index();
            $table->double('revenue')->unsigned()->default(0);
            $table->string('revenue_id')->nullable()->index();
            $table->string('ip');
            $table->string('country')->nullable()->index();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('device')->nullable();
            $table->string('platform')->nullable();
            $table->string('browser')->nullable();
            $table->boolean('is_mobile')->default(false);
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->unsignedBigInteger('ip_id')->nullable()->index();
            $table->unsignedBigInteger('domain_id')->nullable()->index();
            $table->unsignedBigInteger('from_id')->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->unsignedBigInteger('creative_id')->nullable()->index();
            $table->unsignedBigInteger('isp_id')->nullable()->index();
            $table->unsignedBigInteger('list_id')->nullable()->index();
            $table->string('list_type')->nullable()->index();
            $table->unsignedBigInteger('email_id')->index();
            $table->timestamps();
            $table->index(['workspace_id', 'workspace_drop_id', 'created_at'], 'drop_visitors_workspace_drop_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_drop_visitors');
    }
};
