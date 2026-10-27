<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_google_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255); // DMS uses the delegated Workspace admin email as project name/subject.
            $table->text('service_account_json');
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('workspace_google_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('workspace_google_projects')->cascadeOnDelete();
            $table->string('email', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['project_id', 'email']);
            $table->index(['workspace_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_google_accounts');
        Schema::dropIfExists('workspace_google_projects');
    }
};
