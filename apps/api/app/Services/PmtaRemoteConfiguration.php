<?php

namespace App\Services;

use App\Models\ManagedServer;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use RuntimeException;

class PmtaRemoteConfiguration
{
    public function __construct(
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsInstallerSshSessionFactory $sessions,
        private readonly PmtaConfigFileCatalog $files,
    ) {}

    public function open(ManagedServer $server): DmsInstallerSshSession
    {
        if (! $server->is_active || ! $server->is_installed) {
            throw new RuntimeException('Choose an active, installed server.');
        }
        $credential = $this->credentials->resolve($server);

        return $this->sessions->create($server, $credential['secret'], $credential['type']);
    }

    /** @return list<string> */
    public function listFiles(ManagedServer $server): array
    {
        $result = $this->open($server)->exec("find /etc/pmta/conf -type f -printf '%T@ %P\\n' | sort -n | awk '{print \$2}'");

        return $this->files->visibleFiles((string) $result);
    }

    public function workspaceSmtpRouteExists(ManagedServer $server, int $userId, string $virtualMtaName): bool
    {
        if ($userId < 1 || preg_match('/\\A[A-Za-z0-9_.:-]{1,255}\\z/', $virtualMtaName) !== 1) {
            return false;
        }

        $configuration = escapeshellarg('/etc/pmta/conf/workspace_'.$userId);
        $directive = escapeshellarg('<virtual-mta '.$virtualMtaName.'>');
        $result = $this->open($server)->exec(
            'if test -f '.$configuration.' && grep -Fq -- '.$directive.' '.$configuration
                .'; then echo __ALPHALINK_WORKSPACE_ROUTE_PRESENT__; else echo __ALPHALINK_WORKSPACE_ROUTE_MISSING__; fi',
        );

        return is_string($result) && str_contains($result, '__ALPHALINK_WORKSPACE_ROUTE_PRESENT__');
    }

    public function readFile(ManagedServer $server, string $filename): string
    {
        $path = escapeshellarg($this->files->path($filename));
        $result = $this->open($server)->exec('[ -f '.$path.' ] && cat '.$path.' || echo __ALPHALINK_FILE_NOT_FOUND__');
        if (! is_string($result) || $result === '__ALPHALINK_FILE_NOT_FOUND__') {
            throw new RuntimeException('PMTA configuration file not found.');
        }

        return $result;
    }

    public function writeAndReload(ManagedServer $server, string $filename, string $contents): string
    {
        $path = $this->files->path($filename);
        $shellPath = escapeshellarg($path);
        $session = $this->open($server);
        if ($session->exec('[ -f '.$shellPath.' ] && echo yes || echo no') !== 'yes') {
            throw new RuntimeException('File Not Found');
        }
        if (! $session->upload($contents, $path)) {
            throw new RuntimeException('The PMTA configuration file could not be uploaded.');
        }
        $result = (string) $session->exec('pmta reload');
        if (! str_contains($result, 'reloaded')) {
            $diagnostic = trim((string) $session->exec('pmtad --debug'));
            throw new RuntimeException($diagnostic !== '' ? $diagnostic : ($result !== '' ? $result : 'PMTA did not confirm the configuration reload.'));
        }

        return $result;
    }

    public function pushFile(ManagedServer $server, string $filename, string $contents): string
    {
        $path = $this->files->path($filename);
        $shellPath = escapeshellarg($path);
        $session = $this->open($server);
        if ($session->exec('[ -f '.$shellPath.' ] && echo yes || echo no') !== 'yes') {
            throw new RuntimeException('File Not Found');
        }
        if (! $session->upload($contents, $path)) {
            throw new RuntimeException('The PMTA configuration file could not be uploaded.');
        }
        $result = (string) $session->exec('pmta reload');
        if (! str_contains($result, 'reloaded')) {
            $diagnostic = trim((string) $session->exec('pmtad --debug'));
            throw new RuntimeException($diagnostic !== '' ? $diagnostic : ($result !== '' ? $result : 'PMTA did not confirm the configuration reload.'));
        }

        return $result;
    }

    public function pushVmtaConfiguration(ManagedServer $server, int $userId, string $contents): string
    {
        $temporaryPath = $this->files->path('tmp_vmta_'.$userId);
        $configurationPath = $this->files->path('vmta_'.$userId);
        $session = $this->open($server);
        $session->upload($contents, $temporaryPath);

        $reload = (string) $session->exec('pmta reload');
        if (! str_contains($reload, 'reloaded')) {
            $diagnostic = trim((string) $session->exec('pmtad --debug'));
            $session->exec('rm -rf '.escapeshellarg($temporaryPath));
            $session->exec('pmta reload');

            throw new RuntimeException($diagnostic !== '' ? $diagnostic : ($reload !== '' ? $reload : 'PMTA did not confirm the VMTA configuration reload.'));
        }

        $session->exec('cat '.escapeshellarg($temporaryPath).' >> '.escapeshellarg($configurationPath));
        $result = (string) $session->exec('pmta reload');
        $session->exec('rm -rf '.escapeshellarg($temporaryPath));

        return $result;
    }

    public function pushVmtaV2(ManagedServer $server, int $userId, string $contents): string
    {
        $temporaryFile = 'tmp_vmta_'.$userId;
        $configurationFile = 'vmta_'.$userId;
        $temporaryPath = $this->files->path($temporaryFile);
        $configurationPath = $this->files->path($configurationFile);
        $session = $this->open($server);

        // DMS uploads a temporary file, reloads to validate it, appends it to the
        // user's VMTA file, reloads again, then removes the temporary file.
        $session->upload($contents, $temporaryPath);
        $reload = (string) $session->exec('pmta reload');
        if (str_contains($reload, 'reloaded')) {
            $session->exec('cat '.escapeshellarg($temporaryPath).' >> '.escapeshellarg($configurationPath));
            $result = (string) $session->exec('pmta reload');
            $session->exec('rm -rf '.escapeshellarg($temporaryPath));

            return $result;
        }

        $debug = (string) $session->exec('pmtad --debug');
        $session->exec('rm -rf '.escapeshellarg($temporaryPath));
        $session->exec('pmta reload');

        throw new RuntimeException($debug);
    }

    public function installGoogleScript(ManagedServer $server, string $binary, string $service): void
    {
        $session = $this->open($server);
        $session->exec('rm -f /usr/sbin/gs');
        if (! $session->upload($binary, '/usr/sbin/gs')) {
            throw new RuntimeException('The Google Script runtime could not be uploaded.');
        }
        $session->exec('chmod +x /usr/sbin/gs && chown root:root /usr/sbin/gs');
        $exists = trim((string) $session->exec('systemctl list-unit-files | grep -q "^gs.service" && echo y || echo n'));
        if ($exists === 'n') {
            if (! $session->upload($service, '/etc/systemd/system/gs.service')) {
                throw new RuntimeException('The Google Script service could not be uploaded.');
            }
            $session->exec('systemctl daemon-reload && systemctl enable gs.service');
        }
        $result = trim((string) $session->exec('systemctl restart gs.service'));
        if ($result !== '' && preg_match('/failed|error/i', $result) === 1) {
            throw new RuntimeException('The Google Script service could not be restarted.');
        }
    }
}
