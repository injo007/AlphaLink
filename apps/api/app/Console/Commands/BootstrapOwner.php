<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapOwner extends Command
{
    protected $signature = 'alphalink:bootstrap-owner
        {--name= : First owner name}
        {--email= : First owner email}
        {--username= : Existing DMS username used for server-name eligibility}
        {--workspace= : Initial workspace name}
        {--pmta-relay-ips= : Comma-separated stable public IPv4 egress addresses for PMTA relay authorization}
        {--pmta-relay-ips-auto-managed : The relay source addresses were detected from this AlphaLink host}
        {--slug= : Optional workspace address}';

    protected $description = 'Create the first AlphaLink owner, workspace, and short-lived API token';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('Bootstrap is disabled because a user already exists.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Owner name')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Owner email'))));
        $username = trim((string) $this->option('username'));
        $workspaceName = trim((string) ($this->option('workspace') ?: $this->ask('Initial workspace name')));

        if ($name === '' || $workspaceName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A name, workspace name, and valid email address are required.');

            return self::INVALID;
        }

        if (mb_strlen($username) > 255) {
            $this->error('The DMS username cannot exceed 255 characters.');

            return self::INVALID;
        }

        $pmtaRelayIps = $this->validatedPmtaRelayIps((string) $this->option('pmta-relay-ips'));
        if ($pmtaRelayIps === null) {
            $this->error('PMTA relay source addresses must be comma-separated IPv4 addresses.');

            return self::INVALID;
        }

        $password = $this->secret('Owner password (at least 16 characters)');
        $confirmation = $this->secret('Confirm owner password');
        if (! is_string($password) || strlen($password) < 16 || ! hash_equals((string) $password, (string) $confirmation)) {
            $this->error('Passwords must match and contain at least 16 characters.');

            return self::INVALID;
        }

        $slugInput = $this->option('slug');
        $slug = Str::slug(is_string($slugInput) && $slugInput !== '' ? $slugInput : $workspaceName);
        $slug = $slug !== '' ? $slug : 'workspace';
        if (Workspace::query()->where('slug', $slug)->exists()) {
            $this->error('That workspace address is already in use.');

            return self::INVALID;
        }

        $plainTextToken = null;

        $created = DB::transaction(function () use ($name, $email, $username, $password, $workspaceName, $slug, $pmtaRelayIps, &$plainTextToken): bool {
            // Two operators may run this command at once; serialize the final check.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(278767147282)');
            }
            if (User::query()->exists()) {
                return false;
            }

            $user = (new User)->forceFill([
                'name' => $name,
                'email' => $email,
                'username' => $username !== '' ? $username : null,
                'email_verified_at' => now(),
                'password' => $password,
            ]);
            $user->save();

            $workspace = Workspace::query()->create([
                'name' => $workspaceName,
                'slug' => $slug,
                'status' => 'active',
                'created_by' => $user->getKey(),
            ]);

            $workspace->users()->attach($user->getKey(), [
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            if ($pmtaRelayIps !== []) {
                WorkspaceInfrastructureSettings::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'pmta_relay_ips' => $pmtaRelayIps,
                    'pmta_relay_ips_auto_managed' => (bool) $this->option('pmta-relay-ips-auto-managed'),
                ]);
            }

            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $user->getKey(),
                'action' => 'workspace.bootstrap_owner_created',
                'subject_type' => 'workspace',
                'subject_id' => $workspace->getKey(),
                'properties' => ['role_granted' => 'owner'],
                'occurred_at' => now(),
            ]);

            OutboxMessage::query()->create([
                'workspace_id' => $workspace->getKey(),
                'event_type' => 'workspace.created',
                'payload' => ['workspace_id' => $workspace->getKey()],
                'idempotency_key' => (string) Str::uuid(),
                'available_at' => now(),
                'created_at' => now(),
            ]);

            $plainTextToken = $user->createToken('initial-owner', ['*'], now()->addHours(8))->plainTextToken;

            return true;
        });

        if (! $created) {
            $this->error('Bootstrap is disabled because a user already exists.');

            return self::FAILURE;
        }

        $this->info('First owner and workspace created.');
        $this->line('Store this API token securely. It is shown once and expires in 8 hours:');
        $this->line((string) $plainTextToken);

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function validatedPmtaRelayIps(string $value): ?array
    {
        $addresses = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $ip): bool => $ip !== '')));

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return null;
            }
        }

        return $addresses;
    }
}
