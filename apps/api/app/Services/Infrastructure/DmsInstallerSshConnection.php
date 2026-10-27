<?php

namespace App\Services\Infrastructure;

interface DmsInstallerSshConnection
{
    public function getServerPublicHostKey(): string|false;

    public function login(string $username, mixed $credential): bool;

    public function isConnected(): bool;

    public function isAuthenticated(): bool;

    public function exec(string $command): string|false;

    public function uploadString(string $contents, string $destination): bool;

    public function uploadFile(string $localPath, string $destination): bool;

    public function downloadString(string $path): string|false;
}
