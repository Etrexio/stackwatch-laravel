<?php

namespace StackWatch\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use StackWatch\Laravel\Transport\HttpTransport;

class InstallCommand extends Command
{
    protected $signature = 'stackwatch:install 
                            {--api-key= : Your StackWatch API key}
                            {--endpoint= : Custom API endpoint (for self-hosted)}
                            {--queue= : Queue connection to use (sync/redis/database)}
                            {--no-interaction : Run without prompts}';

    protected $description = 'Install and configure StackWatch for your Laravel application';

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║                                                              ║');
        $this->info('║   ███████╗████████╗ █████╗  ██████╗██╗  ██╗██╗    ██╗        ║');
        $this->info('║   ██╔════╝╚══██╔══╝██╔══██╗██╔════╝██║ ██╔╝██║    ██║        ║');
        $this->info('║   ███████╗   ██║   ███████║██║     █████╔╝ ██║ █╗ ██║        ║');
        $this->info('║   ╚════██║   ██║   ██╔══██║██║     ██╔═██╗ ██║███╗██║        ║');
        $this->info('║   ███████║   ██║   ██║  ██║╚██████╗██║  ██╗╚███╔███╔╝        ║');
        $this->info('║   ╚══════╝   ╚═╝   ╚═╝  ╚═╝ ╚═════╝╚═╝  ╚═╝ ╚══╝╚══╝         ║');
        $this->info('║                                                              ║');
        $this->info('║   AI-Powered Application Monitoring for Laravel              ║');
        $this->info('║                                                              ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->info('');

        // Step 1: Publish config
        $this->publishConfig();

        // Step 2: Get API Key
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            $this->error('API key is required. Please run again with --api-key option or enter it when prompted.');
            return Command::FAILURE;
        }

        // Step 3: Get endpoint
        $endpoint = $this->getEndpoint();

        // Step 4: Get queue preference
        $queue = $this->getQueueConnection();

        // Step 5: Update .env
        $this->updateEnvFile($apiKey, $endpoint, $queue);

        // Step 6: Optionally add to log stack
        if (!$this->option('no-interaction') && $this->confirm('Would you like to add StackWatch to your Laravel log stack? (Recommended)', true)) {
            $this->addToLogStack();
        }

        // Step 7: Test connection
        $this->info('');
        $this->info('Testing connection to StackWatch...');
        
        // Force config reload
        config(['stackwatch.api_key' => $apiKey]);
        config(['stackwatch.endpoint' => $endpoint]);
        
        $transport = new HttpTransport();
        
        if ($transport->ping()) {
            $this->info('✓ Successfully connected to StackWatch API');
        } else {
            $this->warn('⚠ Could not verify connection. This might be normal if the API is not yet configured.');
        }

        // Step 8: Show next steps
        $this->showNextSteps();

        return Command::SUCCESS;
    }

    protected function publishConfig(): void
    {
        $this->info('Publishing StackWatch configuration...');

        $configPath = config_path('stackwatch.php');
        
        if (File::exists($configPath)) {
            if (!$this->option('no-interaction') && !$this->confirm('Config file already exists. Overwrite?', false)) {
                $this->info('Keeping existing config.');
                return;
            }
        }

        $this->call('vendor:publish', [
            '--tag' => 'stackwatch-config',
            '--force' => true,
        ]);

        $this->info('✓ Configuration published');
    }

    protected function getApiKey(): ?string
    {
        if ($apiKey = $this->option('api-key')) {
            return $apiKey;
        }

        if ($this->option('no-interaction')) {
            return env('STACKWATCH_API_KEY');
        }

        $this->info('');
        $this->info('You can find your API key in the StackWatch dashboard:');
        $this->info('  → https://stackwatch.dev/dashboard → Settings → API Keys');
        $this->info('');

        return $this->ask('Enter your StackWatch API key');
    }

    protected function getEndpoint(): string
    {
        if ($endpoint = $this->option('endpoint')) {
            return $endpoint;
        }

        if ($this->option('no-interaction')) {
            return 'https://api.stackwatch.dev/v1';
        }

        if ($this->confirm('Are you using a self-hosted StackWatch instance?', false)) {
            return $this->ask('Enter your StackWatch API endpoint', 'https://api.stackwatch.dev/v1');
        }

        return 'https://api.stackwatch.dev/v1';
    }

    protected function getQueueConnection(): string
    {
        if ($queue = $this->option('queue')) {
            return $queue;
        }

        if ($this->option('no-interaction')) {
            return 'sync';
        }

        $choices = [
            'sync' => 'Sync (immediate, recommended for development)',
            'redis' => 'Redis (async, recommended for production)',
            'database' => 'Database (async, no Redis required)',
        ];

        $choice = $this->choice(
            'How should events be sent?',
            array_values($choices),
            0
        );

        return array_flip($choices)[$choice];
    }

    protected function updateEnvFile(string $apiKey, string $endpoint, string $queue): void
    {
        $this->info('Updating .env file...');

        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            $this->warn('.env file not found. Please add the following variables manually.');
            $this->showEnvVariables($apiKey, $endpoint, $queue);
            return;
        }

        $envContent = File::get($envPath);

        // Add or update StackWatch variables
        $variables = [
            'STACKWATCH_API_KEY' => $apiKey,
            'STACKWATCH_ENDPOINT' => $endpoint,
            'STACKWATCH_QUEUE_CONNECTION' => $queue,
            'STACKWATCH_ENABLED' => 'true',
        ];

        foreach ($variables as $key => $value) {
            if (preg_match("/^{$key}=/m", $envContent)) {
                $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $envContent);
            } else {
                $envContent .= "\n{$key}={$value}";
            }
        }

        // Add section header if not present
        if (!str_contains($envContent, '# StackWatch')) {
            $stackwatchSection = "\n\n# StackWatch Configuration\n";
            $envContent = preg_replace(
                "/(STACKWATCH_API_KEY=)/",
                $stackwatchSection . "$1",
                $envContent
            );
        }

        File::put($envPath, $envContent);

        $this->info('✓ Environment variables configured');
    }

    protected function showEnvVariables(string $apiKey, string $endpoint, string $queue): void
    {
        $this->info('');
        $this->info('Add these to your .env file:');
        $this->info('');
        $this->line("# StackWatch Configuration");
        $this->line("STACKWATCH_API_KEY={$apiKey}");
        $this->line("STACKWATCH_ENDPOINT={$endpoint}");
        $this->line("STACKWATCH_QUEUE_CONNECTION={$queue}");
        $this->line("STACKWATCH_ENABLED=true");
        $this->info('');
    }

    protected function addToLogStack(): void
    {
        $loggingPath = config_path('logging.php');
        
        if (!File::exists($loggingPath)) {
            $this->warn('logging.php not found. Please add stackwatch channel manually.');
            return;
        }

        $content = File::get($loggingPath);

        // Check if already configured
        if (str_contains($content, "'stackwatch'") && str_contains($content, 'channels')) {
            $this->info('✓ StackWatch already configured in logging stack');
            return;
        }

        $this->info('');
        $this->info('To add StackWatch to your log stack, update your config/logging.php:');
        $this->info('');
        $this->line("'stack' => [");
        $this->line("    'driver' => 'stack',");
        $this->line("    'channels' => ['single', 'stackwatch'], // Add 'stackwatch' here");
        $this->line("    'ignore_exceptions' => false,");
        $this->line("],");
        $this->info('');
        $this->info('Or add STACKWATCH_AUTO_REGISTER_LOG=true to your .env file.');
    }

    protected function showNextSteps(): void
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║                    Installation Complete!                     ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->info('');
        $this->info('Next steps:');
        $this->info('');
        $this->info('  1. Test your setup:');
        $this->line('     php artisan stackwatch:test');
        $this->info('');
        $this->info('  2. Add middleware to routes (optional):');
        $this->line("     Route::middleware(['stackwatch'])->group(function () { ... });");
        $this->info('');
        $this->info('  3. Capture your first error:');
        $this->line('     StackWatch::captureException($exception);');
        $this->line('     StackWatch::captureMessage("Hello StackWatch!", "info");');
        $this->info('');
        $this->info('  4. View events in your dashboard:');
        $this->line('     https://stackwatch.dev/dashboard');
        $this->info('');
        $this->info('Documentation: https://docs.stackwatch.dev/laravel');
        $this->info('');
    }
}
