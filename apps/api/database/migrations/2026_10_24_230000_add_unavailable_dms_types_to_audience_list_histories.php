<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_list_histories', function (Blueprint $table): void {
            $table->unsignedInteger('opt_out')->nullable();
            $table->unsignedInteger('proxy')->nullable();
            $table->unsignedInteger('bot')->nullable();
            $table->unsignedInteger('blacklist')->nullable();
            $table->unsignedInteger('hard_bounce')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audience_list_histories', function (Blueprint $table): void {
            $table->dropColumn(['opt_out', 'proxy', 'bot', 'blacklist', 'hard_bounce']);
        });
    }
};
