<?php

namespace App\Services\Infrastructure;

class DmsInstallPlatform
{
    /**
     * DMS's ServersInstallFactory rejects non-x86_64 hosts and matches these
     * PRETTY_NAME values before selecting its CentOS 7/9 installer.
     *
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function blockers(array $facts): array
    {
        $blockers = [];
        $architecture = strtolower(trim((string) ($facts['architecture'] ?? '')));
        $release = strtolower(trim((string) ($facts['os_pretty_name'] ?? '')));

        if (! str_contains($architecture, 'x86_64')) {
            $blockers[] = 'DMS bulk installation supports x86_64 architecture only.';
        }

        if ($this->installerFamily($release) === null) {
            $blockers[] = 'DMS bulk installation supports CentOS Linux 7, CentOS Stream 9, Rocky Linux 9, and AlmaLinux 9 only.';
        }

        return $blockers;
    }

    public function installerFamily(string $release): ?string
    {
        $release = strtolower(trim($release));

        if (str_contains($release, 'centos linux 7')) {
            return 'centos7';
        }

        foreach (['centos stream 9', 'rocky linux 9', 'almalinux 9'] as $marker) {
            if (str_contains($release, $marker)) {
                return 'centos9';
            }
        }

        return null;
    }
}
