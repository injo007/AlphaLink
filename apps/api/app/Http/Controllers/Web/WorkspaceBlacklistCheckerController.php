<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWorkspaceBlacklistCheck;
use App\Models\ContactListMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceBlacklistProcess;
use App\Services\Audiences\ProcessWorkspaceBlacklistFile;
use Illuminate\Contracts\View\View;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkspaceBlacklistCheckerController extends Controller
{
    public function index(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);

        if ($request->boolean('json') || $request->wantsJson()) {
            return response()->json([
                'data' => WorkspaceBlacklistProcess::query()
                    ->with('createdBy:id,name')
                    ->where('workspace_id', $workspace->getKey())
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (WorkspaceBlacklistProcess $process): array => $process->statusPayload())
                    ->values(),
            ]);
        }

        return view('console.infrastructure.blacklist-checker.index', [
            'workspace' => $workspace,
            'emailTypes' => [...ContactListMembership::CAMPAIGN_TYPES, ...ContactListMembership::HISTORY_TRACKED_TYPES],
        ]);
    }

    public function upload(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);
        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:txt,csv'],
            'email_type' => [
                'required', 'string',
                Rule::in([...ContactListMembership::CAMPAIGN_TYPES, ...ContactListMembership::HISTORY_TRACKED_TYPES]),
            ],
        ]);

        $lookupKey = (string) config('alphalink.contact_lookup_key');
        abort_unless(strlen($lookupKey) >= 32, 503, 'Contact lookup is not configured for this workspace.');

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        $this->rejectUnsupportedHashInput($file->getRealPath(), $extension);

        $processId = (string) Str::uuid();
        $storagePath = 'blacklist-checks/'.$workspace->getKey().'/'.$processId.'.'.$extension;
        $this->stagePrivateFile($file->getRealPath(), $storagePath);

        $process = null;
        try {
            $process = DB::transaction(fn (): WorkspaceBlacklistProcess => WorkspaceBlacklistProcess::query()->create([
                'id' => $processId,
                'workspace_id' => $workspace->getKey(),
                'created_by' => $request->user()->getKey(),
                'status' => 'queued',
                'email_type' => $validated['email_type'],
                'input_mode' => 'email',
                'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'storage_path' => $storagePath,
                'file_size_bytes' => (int) ($file->getSize() ?? 0),
                'byte_offset' => 0,
                'processed_count' => 0,
                'matched_count' => 0,
                'updated_count' => 0,
                'invalid_count' => 0,
            ]));

            ProcessWorkspaceBlacklistCheck::dispatch($processId);
        } catch (Throwable $exception) {
            if ($process instanceof WorkspaceBlacklistProcess) {
                WorkspaceBlacklistProcess::query()->whereKey($processId)->delete();
            }
            Storage::disk('local')->delete($storagePath);
            report($exception);

            throw ValidationException::withMessages([
                'file' => 'The blacklist process could not be queued. Please retry the upload.',
            ]);
        }

        return response()->json([
            'data' => $process->statusPayload(),
            'message' => 'Blacklist check queued.',
        ], 202);
    }

    public function cancel(Request $request, string $workspace, string $process, ProcessWorkspaceBlacklistFile $processor): JsonResponse
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);
        $processRow = WorkspaceBlacklistProcess::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($process)
            ->firstOrFail();

        $status = $processor->requestCancellation((string) $processRow->getKey(), (string) $workspace->getKey());

        return response()->json([
            'data' => ['id' => (string) $processRow->getKey(), 'status' => $status],
            'message' => $status === 'cancelling' ? 'Cancellation requested.' : 'Process is '.$status.'.',
        ]);
    }

    private function rejectUnsupportedHashInput(string $path, string $extension): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be read.']);
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $value = trim($line);
                if ($extension === 'csv') {
                    $columns = str_getcsv($value);
                    $value = trim((string) ($columns[0] ?? ''));
                }
                if ($value === '') {
                    continue;
                }
                if (preg_match('/^[a-f0-9]{32}$/iD', $value) === 1) {
                    throw ValidationException::withMessages([
                        'file' => 'This DMS file uses 32-character hashes. AlphaLink can currently check email-address files only; no records were changed.',
                    ]);
                }

                return;
            }
        } finally {
            fclose($stream);
        }
    }

    private function stagePrivateFile(string $sourcePath, string $storagePath): void
    {
        $disk = Storage::disk('local');
        $destinationPath = $disk->path($storagePath);
        (new Filesystem)->ensureDirectoryExists(dirname($destinationPath), 0700, true);
        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'xb');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            @unlink($destinationPath);
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be staged privately.']);
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false || ! chmod($destinationPath, 0600)) {
                throw ValidationException::withMessages(['file' => 'The uploaded file could not be staged privately.']);
            }
        } catch (Throwable $exception) {
            @unlink($destinationPath);
            throw $exception;
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    private function adminWorkspace(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        return $workspace;
    }
}
