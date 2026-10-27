<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhpunitDatabaseIsolationTest extends TestCase
{
    public function test_tests_use_an_in_memory_sqlite_database(): void
    {
        $connection = DB::connection();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
    }
}
