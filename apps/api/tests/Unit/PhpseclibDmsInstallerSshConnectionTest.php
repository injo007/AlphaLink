<?php

namespace Tests\Unit;

use App\Services\Infrastructure\PhpseclibDmsInstallerSshConnection;
use Mockery;
use phpseclib3\Net\SFTP;
use Tests\TestCase;

class PhpseclibDmsInstallerSshConnectionTest extends TestCase
{
    public function test_feedback_file_operations_use_the_phpseclib_sftp_api(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('nlist')->once()->with('/etc/pmta/delivered/treated')->andReturn(['.', '..', 'd-2026-10-27-1.csv', false]);
        $sftp->shouldReceive('is_dir')->once()->with('/etc/pmta/delivered/treated')->andReturn(true);
        $sftp->shouldReceive('is_dir')->once()->with('/etc/pmta/delivered/process/2026-10-27')->andReturn(false);
        $sftp->shouldReceive('mkdir')->once()->with('/etc/pmta/delivered/process/2026-10-27', -1, true)->andReturn(true);
        $sftp->shouldReceive('filesize')->once()->with('/etc/pmta/delivered/file.csv')->andReturn(32);
        $sftp->shouldReceive('filemtime')->once()->with('/etc/pmta/delivered/file.csv')->andReturn(1_793_078_400);
        $sftp->shouldReceive('get')->once()->with('/etc/pmta/delivered/file.csv', '/tmp/staged.csv')->andReturn(true);
        $sftp->shouldReceive('rename')->once()->with('/etc/pmta/delivered/file.csv', '/etc/pmta/delivered/archive/file.csv_1')->andReturn(true);

        $connection = new PhpseclibDmsInstallerSshConnection($sftp);

        $this->assertSame(['.', '..', 'd-2026-10-27-1.csv'], $connection->listDirectory('/etc/pmta/delivered/treated'));
        $this->assertTrue($connection->isDirectory('/etc/pmta/delivered/treated'));
        $this->assertTrue($connection->createDirectory('/etc/pmta/delivered/process/2026-10-27'));
        $this->assertSame(32, $connection->fileSize('/etc/pmta/delivered/file.csv'));
        $this->assertSame(1_793_078_400, $connection->fileModifiedAt('/etc/pmta/delivered/file.csv'));
        $this->assertTrue($connection->downloadFile('/etc/pmta/delivered/file.csv', '/tmp/staged.csv'));
        $this->assertTrue($connection->moveFile('/etc/pmta/delivered/file.csv', '/etc/pmta/delivered/archive/file.csv_1'));
    }

    public function test_existing_remote_directory_is_not_created_again_and_failed_download_is_returned(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('is_dir')->once()->with('/etc/pmta/delivered/process/2026-10-27')->andReturn(true);
        $sftp->shouldReceive('get')->once()->with('/etc/pmta/delivered/file.csv', '/tmp/staged.csv')->andReturn(false);
        $connection = new PhpseclibDmsInstallerSshConnection($sftp);

        $this->assertTrue($connection->createDirectory('/etc/pmta/delivered/process/2026-10-27'));
        $this->assertFalse($connection->downloadFile('/etc/pmta/delivered/file.csv', '/tmp/staged.csv'));
    }
}
