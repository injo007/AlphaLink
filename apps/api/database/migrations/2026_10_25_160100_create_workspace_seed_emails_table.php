<?php

use App\Models\WorkspaceSeedList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_seed_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(WorkspaceSeedList::class)->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamps();
            $table->index(['workspace_seed_list_id', 'id']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_seed_emails');
    }
};
