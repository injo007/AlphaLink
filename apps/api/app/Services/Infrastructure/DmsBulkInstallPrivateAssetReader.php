<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Reads installer assets only from an absolute private root without symlinks. */
class DmsBulkInstallPrivateAssetReader
{
    public function __construct(private readonly AlphaLinkInstallerAssetCatalog $catalog = new AlphaLinkInstallerAssetCatalog) {}

    public function read(string $root, string $relativePath, bool $required = true): ?string
    {
        if (! str_starts_with($root, DIRECTORY_SEPARATOR) || is_link($root) || ! is_dir($root)) {
            throw new RuntimeException('The AlphaLink installation asset directory is unavailable.');
        }

        $rootMode = fileperms($root);
        if (! is_int($rootMode) || ($rootMode & 0077) !== 0) {
            throw new RuntimeException('The AlphaLink installation asset directory is not private.');
        }

        $current = rtrim($root, DIRECTORY_SEPARATOR);
        $segments = explode('/', $this->catalog->pathFor($relativePath));
        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('The AlphaLink asset path is unsafe.');
            }

            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                throw new RuntimeException('The AlphaLink asset path contains a symlink.');
            }

            if (! file_exists($current)) {
                if (! $required && $index === array_key_last($segments)) {
                    return null;
                }

                throw new RuntimeException('A required AlphaLink installation asset is missing.');
            }

            if ($index === array_key_last($segments) ? ! is_file($current) : ! is_dir($current)) {
                throw new RuntimeException('The AlphaLink asset path is invalid.');
            }
        }

        $contents = file_get_contents($current);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('An AlphaLink installation asset is empty or unreadable.');
        }

        return $contents;
    }
}
