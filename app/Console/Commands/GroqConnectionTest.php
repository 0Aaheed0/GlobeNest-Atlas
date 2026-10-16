<?php

namespace App\Console\Commands;

use App\Services\ChatCompletionProviderException;
use App\Services\GroqClient;
use Illuminate\Console\Command;

class GroqConnectionTest extends Command
{
    protected $signature = 'groq:test';

    protected $description = 'Make a minimal server-side Groq tool-call connection test.';

    public function handle(GroqClient $client): int
    {
        $model = config('services.groq.model');
        $timeout = min(120, max(1, (int) config('services.groq.timeout', 25)));
        $caBundleExists = is_file(base_path('certs/cacert.pem'));

        $this->line('Model: '.(is_string($model) && $model !== '' ? $model : '(not configured)'));
        $this->line("Total request timeout: {$timeout}s (connection timeout: 5s).");
        $this->line('TLS verification: enabled; project CA bundle certs/cacert.pem is '.($caBundleExists ? 'present.' : 'missing.'));

        try {
            $diagnostics = $client->testConnection();
        } catch (ChatCompletionProviderException $exception) {
            $this->error($exception->getMessage());
            $this->reportDiagnostics($exception->diagnostics);

            return self::FAILURE;
        }

        $this->reportDiagnostics($diagnostics);
        $this->info('Groq connection and synthetic tool-call smoke test succeeded for model '.$model.'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function reportDiagnostics(array $diagnostics): void
    {
        if (isset($diagnostics['http_status']) && is_int($diagnostics['http_status'])) {
            $this->line('HTTP status: '.$diagnostics['http_status'].'.');
        }

        if (isset($diagnostics['finish_reason']) && is_string($diagnostics['finish_reason'])) {
            $this->line('Finish reason: '.$diagnostics['finish_reason'].'.');
        }

        if (isset($diagnostics['tool_calls_present']) && is_bool($diagnostics['tool_calls_present'])) {
            $this->line('tool_calls present: '.($diagnostics['tool_calls_present'] ? 'yes' : 'no').'.');
        }

        if (isset($diagnostics['tool_call_count']) && is_int($diagnostics['tool_call_count'])) {
            $this->line('Tool call count: '.$diagnostics['tool_call_count'].'.');
        }

        if (isset($diagnostics['error_category']) && is_string($diagnostics['error_category'])) {
            $this->line('Error category: '.$diagnostics['error_category'].'.');
        }

        if (isset($diagnostics['retry_after']) && is_string($diagnostics['retry_after'])) {
            $this->line('Retry-After: '.$diagnostics['retry_after'].'.');
        }
    }
}
