<?php

namespace App\Services\Campaigns;

use Symfony\Component\Process\Process;
use Throwable;

class DmsGlobalTestRunner
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array{configured: bool, output: string, error: string, exit_code: int|null}
     */
    public function run(array $configuration): array
    {
        $senderPath = config('global-tests.sender_path');
        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'output' => '',
                'error' => 'The AlphaLink Global Test sender is not configured or is not executable.',
                'exit_code' => null,
            ];
        }

        $directory = storage_path('app/private/global-tests');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return [
                'configured' => true,
                'output' => '',
                'error' => 'Could Not Generate Config: private storage is unavailable.',
                'exit_code' => null,
            ];
        }

        $configPath = tempnam($directory, 'global_test_');
        if ($configPath === false) {
            return [
                'configured' => true,
                'output' => '',
                'error' => 'Could Not Generate Config: private storage is unavailable.',
                'exit_code' => null,
            ];
        }

        try {
            $encodedConfiguration = base64_encode(json_encode($configuration, JSON_THROW_ON_ERROR));
            if (file_put_contents($configPath, $encodedConfiguration, LOCK_EX) === false) {
                return [
                    'configured' => true,
                    'output' => '',
                    'error' => 'Could Not Generate Config: configuration could not be written.',
                    'exit_code' => null,
                ];
            }
            chmod($configPath, 0600);

            $process = new Process([$senderPath, 'global_test', $configPath]);
            $process->setTimeout((float) config('global-tests.timeout_seconds', 1800));
            $process->run();

            return [
                'configured' => true,
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
                'exit_code' => $process->getExitCode(),
            ];
        } catch (Throwable $exception) {
            return [
                'configured' => true,
                'output' => '',
                'error' => $exception->getMessage(),
                'exit_code' => null,
            ];
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    public function isConfigured(): bool
    {
        $senderPath = config('global-tests.sender_path');

        return is_string($senderPath)
            && trim($senderPath) !== ''
            && is_file($senderPath)
            && is_executable($senderPath);
    }
}
