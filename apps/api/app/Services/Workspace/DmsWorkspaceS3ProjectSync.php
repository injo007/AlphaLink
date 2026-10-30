<?php

namespace App\Services\Workspace;

use App\Jobs\SyncWorkspaceGoogleProjectAccounts;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGoogleProject;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;

/** Imports Workspace service-account files from the public S3 folder format used by DMS. */
class DmsWorkspaceS3ProjectSync
{
    /** @return array{message: string, added: int, updated: int, unchanged: int} */
    public function sync(Workspace $workspace, string $link, ?User $actor = null): array
    {
        [$baseUrl, $prefix] = $this->parseS3Link($link);
        $keys = $this->listObjectKeys($baseUrl, $prefix);

        if ($keys === []) {
            throw new RuntimeException('No files found in the given S3 directory.');
        }

        $added = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($keys as $key) {
            $name = pathinfo($key, PATHINFO_FILENAME);
            if ($name === '') {
                continue;
            }

            $response = $this->getObject($baseUrl, $key);
            if (! $response->successful()) {
                throw new RuntimeException('Failed to download S3 project file "'.$name.'" (HTTP '.$response->status().').');
            }

            $contents = $response->body();
            if ($contents === '') {
                continue;
            }

            $projectQuery = WorkspaceGoogleProject::query()
                ->where('workspace_id', $workspace->id)
                ->where('name', $name)
                ->orderBy('id');
            if ($actor !== null) {
                $projectQuery->availableTo($workspace, $actor);
            }
            $project = $projectQuery->first();

            if ($project?->service_account_json === $contents) {
                $unchanged++;

                continue;
            }

            if ($project === null) {
                $project = DB::transaction(fn (): WorkspaceGoogleProject => WorkspaceGoogleProject::query()->create([
                    'workspace_id' => $workspace->id,
                    'name' => $name,
                    'service_account_json' => $contents,
                    'allowed_users' => $this->actorProjectAccess($workspace, $actor),
                    'is_active' => true,
                ]));
                $added++;
            } else {
                $attributes = ['service_account_json' => $contents];
                $allowedUsers = $this->actorProjectAccess($workspace, $actor);
                if ($allowedUsers !== [] && $project->allowed_users !== null) {
                    $attributes['allowed_users'] = array_values(array_unique([
                        ...array_map('intval', $project->allowed_users),
                        ...$allowedUsers,
                    ]));
                }
                $project->forceFill($attributes)->save();
                $updated++;
            }

            SyncWorkspaceGoogleProjectAccounts::dispatch((int) $project->getKey());
        }

        return [
            'message' => "Synced: {$added} added, {$updated} updated",
            'added' => $added,
            'updated' => $updated,
            'unchanged' => $unchanged,
        ];
    }

    /** @return list<int> */
    private function actorProjectAccess(Workspace $workspace, ?User $actor): array
    {
        return $actor !== null && $workspace->pivot?->role === 'operator'
            ? [(int) $actor->getKey()]
            : [];
    }

    /** @return array{string, string} */
    private function parseS3Link(string $link): array
    {
        $parts = parse_url(trim($link));
        if (! is_array($parts)) {
            throw new InvalidArgumentException('Enter an HTTPS S3 folder URL in the format https://bucket.s3.region.amazonaws.com/prefix.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! preg_match('/^([a-z0-9][a-z0-9.-]{1,61}[a-z0-9])\.s3\.([a-z0-9-]+)\.amazonaws\.com$/', $host, $matches)) {
            throw new InvalidArgumentException('Enter an HTTPS S3 folder URL in the format https://bucket.s3.region.amazonaws.com/prefix.');
        }

        $prefix = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        $baseUrl = 'https://'.$matches[1].'.s3.'.$matches[2].'.amazonaws.com';

        return [$baseUrl, $prefix];
    }

    /** @return list<string> */
    private function listObjectKeys(string $baseUrl, string $prefix): array
    {
        $keys = [];
        $continuationToken = null;
        $seenTokens = [];
        $folderPrefix = $prefix === '' ? '' : rtrim($prefix, '/').'/';

        do {
            $query = ['list-type' => '2'];
            if ($folderPrefix !== '') {
                $query['prefix'] = $folderPrefix;
            }
            if ($continuationToken !== null) {
                $query['continuation-token'] = $continuationToken;
            }

            $response = $this->request($baseUrl.'/?'.http_build_query($query));
            if (! $response->successful()) {
                throw new RuntimeException('Failed to list S3 directory (HTTP '.$response->status().').');
            }

            $xml = $this->parseListResponse($response->body());
            foreach ($xml->Contents ?? [] as $object) {
                $key = (string) ($object->Key ?? '');
                if ($key === '' || $key === $folderPrefix || str_ends_with($key, '/')) {
                    continue;
                }
                $keys[] = $key;
            }

            $isTruncated = filter_var((string) ($xml->IsTruncated ?? 'false'), FILTER_VALIDATE_BOOLEAN);
            $nextToken = $isTruncated ? trim((string) ($xml->NextContinuationToken ?? '')) : '';
            if ($nextToken !== '' && isset($seenTokens[$nextToken])) {
                throw new RuntimeException('S3 returned a repeated pagination token.');
            }
            if ($nextToken !== '') {
                $seenTokens[$nextToken] = true;
            }
            $continuationToken = $nextToken !== '' ? $nextToken : null;
        } while ($continuationToken !== null);

        return $keys;
    }

    private function parseListResponse(string $body): SimpleXMLElement
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (! $xml instanceof SimpleXMLElement || $xml->getName() !== 'ListBucketResult') {
            throw new RuntimeException('S3 returned an invalid directory listing.');
        }

        return $xml;
    }

    private function getObject(string $baseUrl, string $key): Response
    {
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));

        return $this->request($baseUrl.'/'.$encodedKey);
    }

    private function request(string $url): Response
    {
        return Http::timeout(120)
            ->withOptions(['allow_redirects' => false])
            ->get($url);
    }
}
