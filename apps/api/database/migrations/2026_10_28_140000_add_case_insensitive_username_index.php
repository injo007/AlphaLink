<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hasCaseInsensitiveDuplicates = DB::table('users')
            ->selectRaw('LOWER(username)')
            ->whereNotNull('username')
            ->groupByRaw('LOWER(username)')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCaseInsensitiveDuplicates) {
            throw new RuntimeException(
                'Cannot enforce case-insensitive username uniqueness until duplicate username spellings are reconciled.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX users_username_case_insensitive_unique ON users (LOWER(username)) WHERE username IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_username_case_insensitive_unique');
    }
};
