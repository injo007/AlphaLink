<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AppErrorLogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsAppErrorsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Errors workspace',
            'slug' => 'errors-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_owner_sees_the_app_errors_page_with_source_route_in_miscellaneous(): void
    {
        $this->app->instance(AppErrorLogReader::class, new class extends AppErrorLogReader
        {
            public function entries(): array
            {
                return [[
                    'date' => '2026-10-24 12:00:00',
                    'level' => 'ERROR',
                    'summary' => 'Synthetic failure summary',
                    'file' => 'laravel-2026-10-24.log',
                ]];
            }
        });

        $this->get(route('console.miscellaneous.app-errors', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('APP ERRORS')
            ->assertSee('Synthetic failure summary')
            ->assertSee('laravel-2026-10-24.log')
            ->assertSee('APP HEALTH')
            ->assertSee('SETTINGS')
            ->assertSee('aria-current="page"', false)
            ->assertSee('Stack traces and common secrets', false);
    }

    public function test_operator_cannot_open_the_admin_error_log_page(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)
            ->get(route('console.miscellaneous.app-errors', $this->workspace->getKey()))
            ->assertForbidden();
    }

    public function test_log_reader_redacts_common_private_values_and_ignores_symlinks(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'alphalink-error-fixture-'.Str::uuid();
        mkdir($directory, 0700, true);
        $log = $directory.DIRECTORY_SEPARATOR.'laravel.log';
        $outside = $directory.DIRECTORY_SEPARATOR.'outside.txt';
        file_put_contents($log, implode("\n", [
            '[2026-10-24 12:00:00] testing.ERROR: Failure for alice@example.test from 203.0.113.5 token=synthetic-token https://example.test/path?key=private',
            '[2026-10-24 12:01:00] testing.ERROR: RuntimeException: safe summary {"exception":"[object] (RuntimeException(code: 0): stack trace private-canary at /media/private/app/vendor/private.php:22)"}',
            '[2026-10-24 12:02:00] testing.ERROR: Query failed (Connection: mysql, Host: private-db, Database: private-database, SQL: select * from users where email = private@example.test)',
            'Stack trace private-canary',
        ])."\n");
        file_put_contents($outside, "[2026-10-24 12:01:00] testing.ERROR: private symlink canary\n");

        try {
            symlink($outside, $directory.DIRECTORY_SEPARATOR.'laravel-linked.log');
            $entries = (new AppErrorLogReader($directory))->entries();

            $this->assertCount(3, $entries);
            $this->assertSame('ERROR', $entries[0]['level']);
            $summaries = implode("\n", array_column($entries, 'summary'));
            $this->assertStringContainsString('[email]', $summaries);
            $this->assertStringContainsString('[ip]', $summaries);
            $this->assertStringContainsString('token=[redacted]', $summaries);
            $this->assertStringContainsString('[url]', $summaries);
            $this->assertStringContainsString('RuntimeException: safe summary', $summaries);
            $this->assertStringNotContainsString('alice@example.test', $summaries);
            $this->assertStringNotContainsString('203.0.113.5', $summaries);
            $this->assertStringNotContainsString('synthetic-token', $summaries);
            $this->assertStringNotContainsString('private-canary', $summaries);
            $this->assertStringNotContainsString('/media/private', $summaries);
            $this->assertStringNotContainsString('private-db', $summaries);
            $this->assertStringNotContainsString('private-database', $summaries);
            $this->assertStringNotContainsString('select * from users', $summaries);
            $this->assertStringNotContainsString('private symlink canary', $summaries);
        } finally {
            @unlink($directory.DIRECTORY_SEPARATOR.'laravel-linked.log');
            @unlink($log);
            @unlink($outside);
            @rmdir($directory);
        }
    }
}
