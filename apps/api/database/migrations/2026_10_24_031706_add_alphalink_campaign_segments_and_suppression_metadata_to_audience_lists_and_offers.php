<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function shouldRun(): bool
    {
        $connection = $this->getConnection();

        return ! Schema::connection($connection)->hasTable('migrations')
            || ! DB::connection($connection)->table('migrations')
                ->where('migration', basename(__FILE__, '.php'))
                ->exists();
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audience_lists_and_offers', function (Blueprint $table) {
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audience_lists_and_offers', function (Blueprint $table) {
            //
        });
    }
};
