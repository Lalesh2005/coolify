<?php

use App\Enums\BackupStorageType;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\VolumeBackup\BackupFailed;
use App\Notifications\VolumeBackup\BackupSuccess;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use phpseclib3\Net\SFTP;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Notification::fake();
    Sleep::fake();
    config(['broadcasting.default' => 'null']);
});

/**
 * A volume backup that uploads to one SFTP storage (rclone).
 *
 * @return array{0: ScheduledVolumeBackup, 1: Team}
 */
function createSftpVolumeBackup(array $backupAttributes = []): array
{
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $team = Team::factory()->create();
    // Notification::fake() records a notification only when the team has a channel enabled for it.
    $team->discordNotificationSettings()->update(['discord_enabled' => true, 'backup_success_discord_notifications' => true, 'backup_failure_discord_notifications' => true]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.10',
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    $storage = S3Storage::create([
        'team_id' => $team->id,
        'type' => BackupStorageType::Sftp,
        'name' => 'sftp storage',
        'region' => 'us-east-1',
        'key' => 'backup',
        'secret' => 'sftp-secret-password',
        'endpoint' => 'sftp://1.1.1.1:22/backups/coolify/data',
        'is_usable' => true,
    ]);
    $storage->forceFill(['sftp_host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhw/KsYTF9TQOwQX1EAM3gI5oxDS9Daz5nNuCixSRXT'])->save();
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        ...$backupAttributes,
    ]);
    $backup->syncS3Storages([$storage->id]);

    return [$backup->fresh(), $team];
}

/**
 * The job tests the storage connection before it uploads. Replaces the phpseclib connection with a stub that accepts
 * every call, so no test opens a real SFTP connection.
 */
function fakeSftpServer(): void
{
    app()->bind(SftpConnectionProvider::class, function () {
        $connection = Mockery::mock(SFTP::class)->shouldIgnoreMissing(true);
        $connection->allows('getServerPublicHostKey')->andReturn('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhw/KsYTF9TQOwQX1EAM3gI5oxDS9Daz5nNuCixSRXT');
        $connection->allows('rawlist')->andReturn([]);

        return new class($connection) implements ConnectionProvider
        {
            public function __construct(private SFTP $connection) {}

            public function provideConnection(): SFTP
            {
                return $this->connection;
            }

            public function disconnect(): void {}
        };
    });
}

function fakeUploadDisks(): void
{
    fakeSftpServer();
    $sshDisk = Storage::fake('ssh-keys');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->zeroOrMoreTimes()->andReturn([]);
    $disk->shouldReceive('delete')->zeroOrMoreTimes()->andReturnTrue();
    Storage::shouldReceive('disk')->with('ssh-keys')->andReturn($sshDisk);
    Storage::shouldReceive('build')->zeroOrMoreTimes()->andReturn($disk);
}

it('reports success when the local archive is created and the SFTP upload succeeds', function () {
    [$backup, $team] = createSftpVolumeBackup();
    fakeUploadDisks();
    fakeRemoteProcesses([
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    expect($execution->message)->toBeNull()
        ->and($execution->status)->toBe('success')
        ->and($execution->s3_uploaded)->toBeTrue();
    Notification::assertSentToTimes($team, BackupSuccess::class, 1);
    Notification::assertSentTo($team, BackupSuccess::class, fn (BackupSuccess $notification) => $notification->warning === null);
    Notification::assertNotSentTo($team, BackupFailed::class);
});

it('fails the backup when the SFTP upload fails', function (string $rcloneError) {
    [$backup, $team] = createSftpVolumeBackup(['disable_local_backup' => true]);
    fakeUploadDisks();
    fakeRemoteProcesses([
        '*du -b*' => '128',
        '*copyto*' => Process::result(errorOutput: $rcloneError, exitCode: 1),
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    $replica = $execution->s3Replicas()->sole();
    expect($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('Storage upload failed: sftp storage:')
        ->and($execution->message)->not->toContain('sftp-secret-password')
        ->and($execution->s3_uploaded)->toBeFalse()
        ->and($replica->s3_uploaded)->toBeFalse()
        // The archive was created, and it stays because the upload failed.
        ->and($execution->size)->toBe(128)
        ->and($execution->local_storage_deleted)->toBeFalse();
    Notification::assertSentToTimes($team, BackupFailed::class, 1);
    Notification::assertSentTo($team, BackupFailed::class, function (BackupFailed $notification) {
        $discord = $notification->toDiscord();

        return $notification->localBackupCreated
            && str_contains($notification->summary(), 'Remote storage upload unsuccessful')
            && str_contains($notification->output, 'Storage upload failed')
            && ! str_contains($notification->output, 'sftp-secret-password')
            && ! str_contains($discord->description, 'was successful')
            && collect($discord->toPayload()['embeds'][0]['fields'])->contains(fn ($field) => ($field['name'] ?? null) === 'Overall status' && ($field['value'] ?? null) === 'Failed');
    });
    Notification::assertNotSentTo($team, BackupSuccess::class);
})->with([
    'disk is full' => ['2026/11/12 13:22:03 ERROR : data-1794489688.tar.gz: Failed to copy: Put mkParentDir failed: mkdir "/backups/coolify/data" failed: sftp: "not enough disk space [free: 24896 | size: 1048576]" (SSH_FX_FAILURE)'],
    'authentication fails' => ['Failed to create file system for "coolify:": NewFs: couldn\'t connect SSH: ssh: handshake failed: ssh: unable to authenticate, attempted methods [none password], no supported methods remain'],
    'remote directory cannot be created' => ['Failed to copy: Put mkParentDir failed: mkdir "/backups/coolify" failed: sftp: "permission denied" (SSH_FX_PERMISSION_DENIED)'],
    'network fails' => ['Failed to create file system for "coolify:": NewFs: couldn\'t connect SSH: dial tcp 203.0.113.20:22: connect: connection refused'],
    'upload times out' => ['Failed to copy: read tcp 10.0.0.2:41234->203.0.113.20:22: i/o timeout'],
    'transfer is interrupted' => ['Failed to copy: unexpected EOF'],
]);

it('fails the backup and deletes nothing of the data when the selected storage no longer exists', function () {
    [$backup, $team] = createSftpVolumeBackup();
    fakeUploadDisks();
    S3Storage::query()->delete();
    fakeRemoteProcesses([
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup->fresh()))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    expect($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('Storage upload failed');
    Notification::assertSentToTimes($team, BackupFailed::class, 1);
    Notification::assertNotSentTo($team, BackupSuccess::class);
});

it('still reports a warning, not a failure, when only the local archive cannot be deleted after a successful upload', function () {
    [$backup, $team] = createSftpVolumeBackup(['disable_local_backup' => true]);
    fakeUploadDisks();
    fakeRemoteProcesses([
        '*du -b*' => '128',
        "*rm -f '*" => Process::result(errorOutput: 'rm: cannot remove: permission denied', exitCode: 1),
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    expect($execution->message)->toContain('local archive could not be deleted')
        ->and($execution->status)->toBe('success')
        ->and($execution->s3_uploaded)->toBeTrue();
    Notification::assertSentTo($team, BackupSuccess::class, fn (BackupSuccess $notification) => $notification->warning !== null);
    Notification::assertNotSentTo($team, BackupFailed::class);
});

it('fails the backup and sends no upload when the local archive cannot be created', function () {
    [$backup, $team] = createSftpVolumeBackup();
    fakeUploadDisks();
    fakeRemoteProcesses([
        '*tar -I*' => Process::result(errorOutput: 'tar: /volume: Cannot open: No such file or directory', exitCode: 2),
        '*' => '',
    ]);

    expect(fn () => (new VolumeBackupJob($backup))->handle())->toThrow(RuntimeException::class);

    expect(ScheduledVolumeBackupExecution::query()->sole()->status)->toBe('failed');
    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), 'copyto'));
    Notification::assertNotSentTo($team, BackupSuccess::class);
});

it('keeps the existing behavior for a backup without remote storage', function () {
    [$backup, $team] = createSftpVolumeBackup(['save_s3' => false]);
    fakeRemoteProcesses([
        '*du -b*' => '128',
        '*' => '',
    ]);

    (new VolumeBackupJob($backup))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    expect($execution->status)->toBe('success')
        ->and($execution->message)->toBeNull();
    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), 'copyto'));
    Notification::assertSentToTimes($team, BackupSuccess::class, 1);
    Notification::assertNotSentTo($team, BackupFailed::class);
});

it('still removes the retained archive of a run that failed only at its upload when retention applies', function () {
    [$backup] = createSftpVolumeBackup(['save_s3' => false, 'retention_amount_locally' => 1]);
    fakeRemoteProcesses([
        '*du -b*' => '128',
        '*' => '',
    ]);
    $failedUpload = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'failed',
        'filename' => '/data/coolify/backups/volumes/test/upload-failed.tar.gz',
        'size' => 64,
    ]);
    ScheduledVolumeBackupExecution::query()->whereKey($failedUpload)->update(['created_at' => now()->subDay()]);
    $failedWhileArchiving = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'failed',
        'filename' => '/data/coolify/backups/volumes/test/partial.tar.gz',
    ]);
    ScheduledVolumeBackupExecution::query()->whereKey($failedWhileArchiving)->update(['created_at' => now()->subDays(2)]);

    (new VolumeBackupJob($backup))->handle();

    expect($failedUpload->fresh())->toBeNull()
        ->and($failedWhileArchiving->fresh())->not->toBeNull();
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), 'rm -f')
        && str_contains(remoteProcessScript($process), 'upload-failed.tar.gz'));
    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), 'partial.tar.gz'));
});