<?php

namespace App\Services\Infrastructure;

class PmtaCatalog
{
    public function versions(): array
    {
        $bundleRoot = config('pmta.bundle_directory');
        $bundleHash = '2ea8e4776b705dec618178bddc1ead3cfcb9fcff5a36bc20309dac160eb46e16';
        $bundleStaged = is_string($bundleRoot)
            && is_file(rtrim($bundleRoot, '/').'/'.$bundleHash.'/manifest.json');

        return [
            '4.0' => [
                'label' => 'PowerMTA 4.0r8',
                'artifact_status' => $bundleStaged ? 'Staged privately' : 'Not staged on this app host',
                'installation_status' => 'Legacy evaluation only',
                'installable' => false,
                'reason' => 'No maintained target OS and license entitlement have passed a clean-host validation.',
            ],
            '4.5' => [
                'label' => 'PowerMTA 4.5',
                'artifact_status' => 'Package and matching license missing',
                'installation_status' => 'Unavailable',
                'installable' => false,
                'reason' => 'Supply and validate the exact 4.5 build and matching license.',
            ],
            '5.0' => [
                'label' => 'PowerMTA 5.0',
                'artifact_status' => 'Package and matching license missing',
                'installation_status' => 'Preferred after validation',
                'installable' => false,
                'reason' => 'Supply and validate the exact 5.0 build and matching license.',
            ],
        ];
    }
}
