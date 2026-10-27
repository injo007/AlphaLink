<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\PmtaBundleDefinition;
use Illuminate\Console\Command;
use RuntimeException;
use ZipArchive;

class StagePmtaBundle extends Command
{
    protected $signature = 'alphalink:pmta:stage {archive : Absolute path to the supplied PMTA ZIP}';

    protected $description = 'Validate and stage the reviewed PowerMTA bundle in private storage';

    public function handle(): int
    {
        try {
            $this->stage();

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function stage(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required.');
        }

        $archivePath = realpath((string) $this->argument('archive'));
        if ($archivePath === false || ! is_file($archivePath)) {
            throw new RuntimeException('PMTA archive was not found.');
        }
        if (! hash_equals(PmtaBundleDefinition::ARCHIVE_SHA256, hash_file('sha256', $archivePath))) {
            throw new RuntimeException('PMTA archive does not match the reviewed bundle.');
        }

        $directory = config('pmta.bundle_directory');
        if (! is_string($directory) || ! str_starts_with($directory, '/')) {
            throw new RuntimeException('ALPHALINK_PMTA_BUNDLE_DIRECTORY must be an absolute private directory.');
        }

        $workspace = realpath(dirname(base_path(), 2));
        if ($workspace === DIRECTORY_SEPARATOR) {
            $workspace = realpath(base_path());
        }
        $parent = realpath(dirname($directory));
        if ($parent === false || ! is_dir($parent) || is_link($directory)) {
            throw new RuntimeException('Create a private PMTA bundle parent directory first.');
        }
        if ((fileperms($parent) & 0077) !== 0) {
            throw new RuntimeException('The PMTA bundle parent directory must be private (mode 0700).');
        }
        if ($workspace !== false && ($parent === $workspace || str_starts_with($parent, $workspace.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('PMTA bundle storage must be outside the shared workspace.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create the PMTA bundle directory.');
        }
        chmod($directory, 0700);

        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('PMTA archive failed ZIP consistency validation.');
        }

        try {
            $expectedNames = array_merge(['pmta/'], array_keys(PmtaBundleDefinition::FILES));
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false || ! in_array($entry['name'], $expectedNames, true) || in_array($entry['name'], $names, true)) {
                    throw new RuntimeException('PMTA archive has an unexpected or duplicate entry.');
                }
                $names[] = $entry['name'];
                $zip->getExternalAttributesIndex($index, $opsys, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if ($entry['name'] === 'pmta/' ? $type !== 0040000 : $type !== 0100000) {
                    throw new RuntimeException('PMTA archive contains an unsupported entry type.');
                }
                if (isset(PmtaBundleDefinition::FILES[$entry['name']]) && $entry['size'] !== PmtaBundleDefinition::FILES[$entry['name']]['size']) {
                    throw new RuntimeException('PMTA archive entry size differs from the reviewed bundle.');
                }
            }
            sort($names);
            sort($expectedNames);
            if ($names !== $expectedNames) {
                throw new RuntimeException('PMTA archive is missing a required entry.');
            }

            $target = $directory.DIRECTORY_SEPARATOR.PmtaBundleDefinition::ARCHIVE_SHA256;
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('This PMTA bundle is already staged; existing files were not replaced.');
            }
            $temporary = $directory.DIRECTORY_SEPARATOR.'.staging-'.bin2hex(random_bytes(12));
            if (! mkdir($temporary, 0700)) {
                throw new RuntimeException('Could not create a private staging directory.');
            }

            try {
                foreach (PmtaBundleDefinition::FILES as $source => $expected) {
                    $input = $zip->getStream($source);
                    $output = fopen($temporary.DIRECTORY_SEPARATOR.$expected['name'], 'xb');
                    if ($input === false || $output === false) {
                        throw new RuntimeException('Could not stream a PMTA archive entry.');
                    }
                    $bytes = stream_copy_to_stream($input, $output);
                    fclose($input);
                    fclose($output);
                    $stagedFile = $temporary.DIRECTORY_SEPARATOR.$expected['name'];
                    chmod($stagedFile, 0600);
                    if ($bytes !== $expected['size'] || ! hash_equals($expected['sha256'], hash_file('sha256', $stagedFile))) {
                        throw new RuntimeException('PMTA archive entry failed byte verification.');
                    }
                }

                $manifestPath = $temporary.DIRECTORY_SEPARATOR.'manifest.json';
                if (file_put_contents($manifestPath, json_encode(PmtaBundleDefinition::manifest(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                    throw new RuntimeException('Could not write the PMTA bundle manifest.');
                }
                chmod($manifestPath, 0600);
                if (! rename($temporary, $target)) {
                    throw new RuntimeException('Could not publish the staged PMTA bundle.');
                }
            } finally {
                if (is_dir($temporary)) {
                    foreach (glob($temporary.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                        unlink($file);
                    }
                    rmdir($temporary);
                }
            }

            $this->info('PowerMTA 4.0r8 x86_64 bundle staged in private storage.');
            $this->line('Server compatibility and license validity remain unverified. The supplied config is retained only as a reference.');
        } finally {
            $zip->close();
        }
    }
}
