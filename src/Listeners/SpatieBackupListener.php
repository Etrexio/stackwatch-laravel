<?php

namespace StackWatch\Laravel\Listeners;

use Illuminate\Support\Facades\Log;
use StackWatch\Laravel\StackWatch;

/**
 * Listener for Spatie Laravel Backup events.
 *
 * @see https://spatie.be/docs/laravel-backup
 */
class SpatieBackupListener
{
    protected StackWatch $stackWatch;

    public function __construct(StackWatch $stackWatch)
    {
        $this->stackWatch = $stackWatch;
    }

    /**
     * Handle backup successful event.
     */
    public function handleBackupWasSuccessful($event): void
    {
        $this->stackWatch->captureEvent('backup', 'info', 'Backup completed successfully', [
            'disk' => $event->backupDestination?->diskName() ?? 'unknown',
            'backup_name' => $event->backupDestination?->backupName() ?? 'unknown',
            'size' => $this->formatSize($event->backupDestination?->newestBackup()?->sizeInBytes() ?? 0),
            'size_bytes' => $event->backupDestination?->newestBackup()?->sizeInBytes() ?? 0,
            'path' => $event->backupDestination?->newestBackup()?->path() ?? null,
            'status' => 'success',
        ]);

        $this->stackWatch->addBreadcrumb('backup', 'Backup completed', [
            'disk' => $event->backupDestination?->diskName(),
        ], 'info');
    }

    /**
     * Handle backup failed event.
     */
    public function handleBackupHasFailed($event): void
    {
        $context = [
            'disk' => $event->backupDestination?->diskName() ?? 'unknown',
            'backup_name' => $event->backupDestination?->backupName() ?? 'unknown',
            'status' => 'failed',
        ];

        if ($event->exception) {
            $context['exception'] = [
                'class' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
                'file' => $event->exception->getFile(),
                'line' => $event->exception->getLine(),
            ];

            $this->stackWatch->captureException($event->exception, [
                'backup_context' => $context,
                'event_type' => 'backup_failed',
            ]);
        } else {
            $this->stackWatch->captureEvent('backup', 'error', 'Backup failed', $context);
        }

        $this->stackWatch->addBreadcrumb('backup', 'Backup failed', $context, 'error');
    }

    /**
     * Handle cleanup successful event.
     */
    public function handleCleanupWasSuccessful($event): void
    {
        $this->stackWatch->captureEvent('backup', 'info', 'Backup cleanup completed', [
            'disk' => $event->backupDestination?->diskName() ?? 'unknown',
            'status' => 'cleanup_success',
        ]);
    }

    /**
     * Handle cleanup failed event.
     */
    public function handleCleanupHasFailed($event): void
    {
        $context = [
            'disk' => $event->backupDestination?->diskName() ?? 'unknown',
            'status' => 'cleanup_failed',
        ];

        if ($event->exception) {
            $context['exception'] = [
                'class' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
            ];

            $this->stackWatch->captureException($event->exception, [
                'backup_context' => $context,
                'event_type' => 'backup_cleanup_failed',
            ]);
        } else {
            $this->stackWatch->captureEvent('backup', 'error', 'Backup cleanup failed', $context);
        }
    }

    /**
     * Handle healthy backup found event.
     */
    public function handleHealthyBackupWasFound($event): void
    {
        $this->stackWatch->captureEvent('backup', 'info', 'Backup health check passed', [
            'disk' => $event->backupDestinationStatus?->backupDestination()?->diskName() ?? 'unknown',
            'status' => 'healthy',
            'amount_of_backups' => $event->backupDestinationStatus?->amountOfBackups() ?? 0,
            'newest_backup_age_in_days' => $event->backupDestinationStatus?->newestBackupAgeInDays() ?? null,
            'used_storage' => $this->formatSize($event->backupDestinationStatus?->usedStorage() ?? 0),
        ]);
    }

    /**
     * Handle unhealthy backup found event.
     */
    public function handleUnhealthyBackupWasFound($event): void
    {
        $failures = [];
        if ($event->backupDestinationStatus?->getHealthCheckFailure()) {
            $failures = array_map(
                fn($check) => $check->getMessage(),
                $event->backupDestinationStatus->getHealthCheckFailure()->toArray()
            );
        }

        $this->stackWatch->captureEvent('backup', 'error', 'Backup health check failed', [
            'disk' => $event->backupDestinationStatus?->backupDestination()?->diskName() ?? 'unknown',
            'status' => 'unhealthy',
            'failures' => $failures,
            'amount_of_backups' => $event->backupDestinationStatus?->amountOfBackups() ?? 0,
            'newest_backup_age_in_days' => $event->backupDestinationStatus?->newestBackupAgeInDays() ?? null,
        ]);

        $this->stackWatch->addBreadcrumb('backup', 'Unhealthy backup detected', [
            'failures' => $failures,
        ], 'error');
    }

    /**
     * Format bytes to human readable size.
     */
    protected function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Subscribe to Spatie Backup events.
     *
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        return [
            'Spatie\Backup\Events\BackupWasSuccessful' => 'handleBackupWasSuccessful',
            'Spatie\Backup\Events\BackupHasFailed' => 'handleBackupHasFailed',
            'Spatie\Backup\Events\CleanupWasSuccessful' => 'handleCleanupWasSuccessful',
            'Spatie\Backup\Events\CleanupHasFailed' => 'handleCleanupHasFailed',
            'Spatie\Backup\Events\HealthyBackupWasFound' => 'handleHealthyBackupWasFound',
            'Spatie\Backup\Events\UnhealthyBackupWasFound' => 'handleUnhealthyBackupWasFound',
        ];
    }
}
