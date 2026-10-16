<?php

namespace App\Console\Commands;

use App\Services\OpenRouterClient;
use App\Services\OpenRouterProviderException;
use Illuminate\Console\Command;

class OpenRouterConnectionTest extends Command
{
    protected $signature = 'openrouter:test';

    protected $description = 'Make a minimal server-side OpenRouter connection test.';

    public function handle(OpenRouterClient $client): int
    {
        $model = config('services.openrouter.model');
        $timeout = max(1, (int) config('services.openrouter.timeout', 25));
        $caBundleExists = is_file(base_path('certs/cacert.pem'));

        $this->line('Model: '.(is_string($model) && $model !== '' ? $model : '(not configured)'));
        $this->line("Total request timeout: {$timeout}s (connection timeout: 5s).");
        $this->line('TLS verification: enabled; project CA bundle certs/cacert.pem is '.($caBundleExists ? 'present.' : 'missing.'));

        try {
            $diagnostics = $client->testConnection();
        } catch (OpenRouterProviderException $exception) {
            $this->error($exception->getMessage());
            $this->reportDiagnostics($exception->diagnostics);

            return self::FAILURE;
        }

        $this->reportDiagnostics($diagnostics);
        $this->info('OpenRouter connection and tool-call smoke test succeeded for model '.$model.'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function reportDiagnostics(array $diagnostics): void
    {
        $failureType = $diagnostics['failure_type'] ?? null;

        if (is_string($failureType)) {
            $description = match ($failureType) {
                'tls_or_certificate_failure' => 'TLS or certificate validation failed.',
                'response_timeout_after_tls' => 'TLS connected; the request timed out waiting for OpenRouter response headers. Provider or inference delay is possible.',
                'response_body_timeout' => 'OpenRouter began responding, but the response body timed out.',
                'connect_or_tls_timeout' => 'The network connection started, but the TLS handshake did not complete before timeout.',
                'timeout_phase_unavailable' => 'The request timed out, but transport statistics were unavailable to identify the phase.',
                'http_response' => 'OpenRouter returned an HTTP error response.',
                'tool_call_validation' => 'OpenRouter returned a successful response, but it did not match the required tool-call result.',
                default => 'The failure occurred before a response was received.',
            };

            $this->line('Diagnostic: '.$description);
        }

        if (isset($diagnostics['elapsed_seconds']) && is_numeric($diagnostics['elapsed_seconds'])) {
            $this->line('Elapsed: '.number_format((float) $diagnostics['elapsed_seconds'], 2).'s.');
        }

        if (isset($diagnostics['connect_time']) && is_numeric($diagnostics['connect_time'])) {
            $this->line('Connection established after: '.number_format((float) $diagnostics['connect_time'], 3).'s.');
        }

        if (isset($diagnostics['appconnect_time']) && is_numeric($diagnostics['appconnect_time'])
            && (float) $diagnostics['appconnect_time'] > 0) {
            $this->line('TLS handshake completed after: '.number_format((float) $diagnostics['appconnect_time'], 3).'s.');
        }

        if (isset($diagnostics['ssl_verify_result']) && $diagnostics['ssl_verify_result'] === 0) {
            $this->line('CA verification: passed (cURL verification result 0).');
        }

        if (isset($diagnostics['starttransfer_time']) && is_numeric($diagnostics['starttransfer_time'])
            && (float) $diagnostics['starttransfer_time'] > 0) {
            $this->line('First response byte received after: '.number_format((float) $diagnostics['starttransfer_time'], 3).'s.');
        } elseif (($diagnostics['response_received'] ?? null) === false) {
            $this->line('First response byte: not received.');
        }

        if (isset($diagnostics['errno']) && is_int($diagnostics['errno'])) {
            $this->line('cURL error number: '.$diagnostics['errno'].'.');
        }

        if (isset($diagnostics['http_status']) && is_int($diagnostics['http_status'])) {
            $this->line('HTTP status: '.$diagnostics['http_status'].'.');
        } elseif (($diagnostics['http_code'] ?? null) === 0) {
            $this->line('HTTP status: no response received.');
        }

        if (isset($diagnostics['provider_error_message']) && is_string($diagnostics['provider_error_message'])) {
            $this->line('Provider error message: '.$diagnostics['provider_error_message']);
        }

        if (isset($diagnostics['metadata_error_type']) && is_string($diagnostics['metadata_error_type'])) {
            $this->line('Provider error type: '.$diagnostics['metadata_error_type']);
        }

        if (isset($diagnostics['provider_code']) && is_string($diagnostics['provider_code'])) {
            $this->line('Provider error code: '.$diagnostics['provider_code']);
        }

        if (isset($diagnostics['provider_name']) && is_string($diagnostics['provider_name'])) {
            $this->line('Upstream provider: '.$diagnostics['provider_name']);
        }

        if (isset($diagnostics['retry_after']) && is_string($diagnostics['retry_after'])) {
            $this->line('Retry-After: '.$diagnostics['retry_after'].'.');
        }

        if (isset($diagnostics['rate_limit_source']) && is_string($diagnostics['rate_limit_source'])) {
            $description = match ($diagnostics['rate_limit_source']) {
                'openrouter_account' => 'OpenRouter account or usage limits',
                'upstream_provider' => 'upstream provider rate limit',
                default => 'undetermined (the response did not identify whether this is an account or upstream-provider limit)',
            };

            $this->line('Rate limit source: '.$description.'.');
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
    }
}
