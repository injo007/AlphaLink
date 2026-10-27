<?php

namespace App\Services;

use InvalidArgumentException;

class PmtaConfigFileCatalog
{
    public function isSafeFilename(string $filename): bool
    {
        if ($filename === '' || strlen($filename) > 120 || preg_match('/\A[A-Za-z0-9_.-]+\z/', $filename) !== 1) {
            return false;
        }

        return $filename !== '.' && $filename !== '..';
    }

    public function path(string $filename): string
    {
        if (! $this->isSafeFilename($filename)) {
            throw new InvalidArgumentException('Choose a valid PMTA configuration filename.');
        }

        return '/etc/pmta/conf/'.$filename;
    }

    /** @return list<string> */
    public function visibleFiles(string $listing): array
    {
        $files = preg_split('/\r\n|\r|\n/', trim($listing)) ?: [];

        return array_values(array_unique(array_filter($files, fn (string $file): bool => $this->isSafeFilename($file))));
    }
}
