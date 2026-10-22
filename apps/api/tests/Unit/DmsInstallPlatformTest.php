<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsInstallPlatform;
use Tests\TestCase;

class DmsInstallPlatformTest extends TestCase
{
    public function test_it_accepts_the_four_release_families_selected_by_dms(): void
    {
        $platform = new DmsInstallPlatform;

        foreach ([
            'CentOS Linux 7 (Core)',
            'CentOS Stream 9',
            'Rocky Linux 9.5 (Blue Onyx)',
            'AlmaLinux 9.6 (Sage Margay)',
        ] as $release) {
            $this->assertSame([], $platform->blockers([
                'architecture' => 'x86_64',
                'os_pretty_name' => $release,
            ]), $release);
        }
    }

    public function test_it_blocks_missing_architecture_or_a_release_dms_factory_does_not_match(): void
    {
        $this->assertSame([
            'DMS bulk installation supports x86_64 architecture only.',
            'DMS bulk installation supports CentOS Linux 7, CentOS Stream 9, Rocky Linux 9, and AlmaLinux 9 only.',
        ], (new DmsInstallPlatform)->blockers([
            'architecture' => 'aarch64',
            'os_pretty_name' => 'Ubuntu 24.04.1 LTS',
        ]));
    }
}
