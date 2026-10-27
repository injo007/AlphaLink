<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->boolean('is_tracked')->default(true);
            $table->boolean('is_fresh_locked')->default(false);
            $table->boolean('is_delivery_locked')->default(false);
            $table->boolean('is_open_locked')->default(false);
            $table->boolean('is_click_locked')->default(false);
            $table->boolean('is_lead_locked')->default(true);
            $table->boolean('is_unsubscribe_locked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->dropColumn([
                'is_tracked',
                'is_fresh_locked',
                'is_delivery_locked',
                'is_open_locked',
                'is_click_locked',
                'is_lead_locked',
                'is_unsubscribe_locked',
            ]);
        });
    }
};
