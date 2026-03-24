<?php

namespace StackWatch\Laravel\Console\Commands;

use Illuminate\Console\Command;
use StackWatch\Laravel\PerformanceBuffer;

class BufferStatusCommand extends Command
{
    protected $signature = 'stackwatch:buffer 
                            {action=status : Action to perform: status, flush, clear}';

    protected $description = 'Manage the StackWatch performance buffer';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'status' => $this->showStatus(),
            'flush' => $this->flushBuffer(),
            'clear' => $this->clearBuffer(),
            default => $this->invalidAction($action),
        };
    }

    protected function showStatus(): int
    {
        $stats = PerformanceBuffer::getStats();
        $buffer = PerformanceBuffer::getBuffer();

        $this->info('StackWatch Performance Buffer Status');
        $this->newLine();
        
        $this->table(['Metric', 'Value'], [
            ['Total Requests', $stats['total_count']],
            ['Unique Transactions', $stats['unique_transactions']],
            ['Last Flush', $stats['last_flush'] ? date('Y-m-d H:i:s', $stats['last_flush']) : 'Never'],
            ['Seconds Since Flush', $stats['seconds_since_flush'] ?? 'N/A'],
            ['Storage Path', $stats['storage_path']],
        ]);

        if (!empty($buffer)) {
            $this->newLine();
            $this->info('Buffered Transactions:');
            
            $batchSize = config('stackwatch.performance.aggregate.batch_size', 50);
            $readyTransactions = PerformanceBuffer::getReadyTransactions();
            
            $rows = [];
            foreach ($buffer as $name => $data) {
                $avgDuration = $data['count'] > 0 ? round($data['total_duration'] / $data['count'], 2) : 0;
                $isReady = in_array($name, $readyTransactions);
                $progress = $data['count'] . '/' . $batchSize;
                
                $rows[] = [
                    $name,
                    $progress,
                    $isReady ? '✓ Ready' : 'Waiting',
                    $avgDuration . 'ms',
                    round($data['min_duration'], 2) . 'ms',
                    round($data['max_duration'], 2) . 'ms',
                    $data['error_count'],
                ];
            }
            
            $this->table(
                ['Transaction', 'Count', 'Status', 'Avg', 'Min', 'Max', 'Errors'],
                $rows
            );
            
            if (!empty($readyTransactions)) {
                $this->newLine();
                $this->info(count($readyTransactions) . ' transaction(s) ready to flush.');
            }
        }

        // Show config
        $this->newLine();
        $this->info('Configuration:');
        $this->table(['Setting', 'Value'], [
            ['batch_size', config('stackwatch.performance.aggregate.batch_size', 50)],
            ['flush_interval', config('stackwatch.performance.aggregate.flush_interval', 60) . 's'],
            ['min_flush_count', config('stackwatch.performance.aggregate.min_flush_count', 5)],
        ]);

        return Command::SUCCESS;
    }

    protected function flushBuffer(): int
    {
        $stats = PerformanceBuffer::getStats();
        
        if ($stats['total_count'] === 0) {
            $this->warn('Buffer is empty, nothing to flush.');
            return Command::SUCCESS;
        }

        $this->info("Flushing {$stats['total_count']} requests from {$stats['unique_transactions']} transactions...");
        
        // Get the StackWatch instance and flush
        $stackWatch = app(\StackWatch\Laravel\StackWatch::class);
        $buffer = PerformanceBuffer::flush();

        foreach ($buffer as $transactionName => $data) {
            if ($data['count'] === 0) {
                continue;
            }

            $avgDuration = $data['total_duration'] / $data['count'];
            $avgMemory = $data['total_memory'] / $data['count'];
            $errorRate = ($data['error_count'] / $data['count']) * 100;

            $message = $transactionName . ' - avg ' . round($avgDuration, 2) . 'ms (' . $data['count'] . ' requests)';
            if ($data['error_count'] > 0) {
                $message .= ' [' . $data['error_count'] . ' errors]';
            }

            $stackWatch->capturePerformance([
                'name' => $transactionName,
                'message' => $message,
                'duration_ms' => round($avgDuration, 2),
                'operation' => 'http.aggregated',
                'status' => $data['error_count'] > 0 ? 'degraded' : 'ok',
                'memory_peak_mb' => round($avgMemory, 2),
                'context' => [
                    'aggregated' => true,
                    'route' => $data['route'] ?? null,
                    'request_count' => $data['count'],
                    'min_duration_ms' => round($data['min_duration'], 2),
                    'max_duration_ms' => round($data['max_duration'], 2),
                    'avg_duration_ms' => round($avgDuration, 2),
                    'error_count' => $data['error_count'],
                    'error_rate_percent' => round($errorRate, 2),
                    'status_codes' => $data['status_codes'],
                    'manual_flush' => true,
                ],
                'tags' => [
                    'aggregated' => 'true',
                    'request_count' => (string) $data['count'],
                ],
            ]);

            $this->line("  ✓ {$transactionName}: {$data['count']} requests");
        }

        $this->newLine();
        $this->info('Buffer flushed successfully!');

        return Command::SUCCESS;
    }

    protected function clearBuffer(): int
    {
        $stats = PerformanceBuffer::getStats();
        
        if ($stats['total_count'] === 0) {
            $this->warn('Buffer is already empty.');
            return Command::SUCCESS;
        }

        if (!$this->confirm("This will discard {$stats['total_count']} buffered requests. Continue?")) {
            return Command::FAILURE;
        }

        PerformanceBuffer::flush(); // Clears without sending
        $this->info('Buffer cleared.');

        return Command::SUCCESS;
    }

    protected function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Available actions: status, flush, clear');
        return Command::FAILURE;
    }
}
