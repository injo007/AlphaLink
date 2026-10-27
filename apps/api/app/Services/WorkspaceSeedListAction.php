<?php

namespace App\Services;

use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSeedEmail;
use App\Models\WorkspaceSeedList;
use Illuminate\Support\Carbon;

class WorkspaceSeedListAction
{
    /** @param list<string> $emails */
    public function upload(WorkspaceSeedList $seedList, array $emails): int
    {
        $validEmails = array_values(array_filter($emails, fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
        if ($validEmails === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(fn (string $email): array => [
            'workspace_seed_list_id' => $seedList->getKey(),
            'email' => $email,
            'created_at' => $now,
            'updated_at' => $now,
        ], $validEmails);

        // DMS's insert() boolean is coerced by SeedListAction's int return type.
        return (int) WorkspaceSeedEmail::query()->insert($rows);
    }

    /**
     * @param  list<string>  $ids
     */
    public function removeEmails(WorkspaceSeedList $seedList, array $ids): int
    {
        return $seedList->emails()->whereIn('id', $ids)->delete();
    }

    public function cleanBouncedEmails(
        Workspace $workspace,
        User $actor,
        ManagedServer $server,
        string $date,
        bool $workspaceAdmin,
        PmtaRemoteConfiguration $remote,
    ): int {
        $archiveDate = Carbon::parse($date)->format('Y-m-d');
        $command = "cat /etc/pmta/bounced/archive/{$archiveDate}/* | awk -F, -v FPAT='[^,]*|\"[^\"]*\"' '$5 ~ /-w/ {print $12}' | sort | uniq";
        $output = (string) $remote->open($server)->exec($command);
        if ($output === '' || str_contains($output, 'No such file or directory')) {
            return 0;
        }

        $emails = array_map('trim', explode("\n", $output));
        $query = WorkspaceSeedEmail::query()
            ->whereHas('seedList', fn ($seedLists) => $seedLists->where('workspace_id', $workspace->getKey()))
            ->whereIn('email', $emails);

        if (! $workspaceAdmin) {
            $query->whereHas('seedList', fn ($seedLists) => $seedLists->where('user_id', $actor->getKey()));
        }

        return $query->delete();
    }
}
