<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->text('profile')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('contact_list_memberships')->whereNotNull('profile')->exists()) {
            throw new RuntimeException('Cannot remove membership profiles after List-specific attributes have been stored. Use a forward migration.');
        }

        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->dropColumn('profile');
        });
    }
};
