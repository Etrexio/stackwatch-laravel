<?php

namespace StackWatch\Laravel\Console\Commands;

use Illuminate\Console\Command;
use StackWatch\Laravel\StackWatch;

class DeployCommand extends Command
{
    protected $signature = 'stackwatch:deploy
                            {--release= : Release version (defaults to STACKWATCH_RELEASE env)}
                            {--environment= : Environment (defaults to STACKWATCH_ENVIRONMENT env)}';

    protected $description = 'Notify StackWatch of a new deployment';

    public function handle(StackWatch $stackWatch): int
    {
        $release = $this->option('release') ?? config('stackwatch.release');
        $environment = $this->option('environment') ?? config('stackwatch.environment');

        if (empty($release)) {
            $this->error('Release version is required. Use --release option or set STACKWATCH_RELEASE env');

            return Command::FAILURE;
        }

        $this->info("Notifying StackWatch of deployment...");
        $this->info("  Release: {$release}");
        $this->info("  Environment: {$environment}");

        $eventId = $stackWatch->captureMessage(
            "Deployed {$release} to {$environment}",
            'info',
            [
                'type' => 'deployment',
                'release' => $release,
                'environment' => $environment,
                'deployed_at' => now()->toIso8601String(),
                'deployer' => get_current_user(),
                'hostname' => gethostname(),
            ]
        );

        if ($eventId) {
            $this->info('✓ Deployment notification sent');

            return Command::SUCCESS;
        }

        $this->warn('⚠ Failed to send deployment notification');

        return Command::FAILURE;
    }
}
