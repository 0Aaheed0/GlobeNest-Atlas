<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqClient implements ChatCompletionClient
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    private ?int $lastHttpStatus = null;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function complete(array $payload): array
    {
        $apiKey = config('services.groq.key');
        $model = config('services.groq.model');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new ChatCompletionProviderException('Atlas AI is not configured. Set GROQ_API_KEY on the server.', 503);
        }

        if (! is_string($model) || trim($model) === '') {
            throw new ChatCompletionProviderException('Atlas AI has no model configured. Set GROQ_MODEL on the server.', 503);
        }

        $timeout = min(120, max(1, (int) config('services.groq.timeout', 25)));

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withOptions(['verify' => base_path('certs/cacert.pem')])
                ->connectTimeout(5)
                ->timeout($timeout)
                ->withToken($apiKey)
                ->post(self::ENDPOINT, ['model' => $model] + $this->normalizePayload($payload));
        } catch (ConnectionException $exception) {
            $timedOut = preg_match('/timed?\s*out|timeout|cURL error 28/i', $exception->getMessage()) === 1;
            $message = $timedOut
                ? 'Groq did not respond before the request timed out. Try again later.'
                : 'Groq could not be reached. Check the server connection and try again.';
            $diagnostics = [
                'failure_type' => $timedOut ? 'timeout' : 'connection_failure',
                'error_category' => $timedOut ? 'timeout' : 'connection_failure',
                'model' => $model,
                'timeout_seconds' => $timeout,
                'tls_verification_enabled' => true,
                'ca_bundle' => 'certs/cacert.pem',
                'ca_bundle_exists' => is_file(base_path('certs/cacert.pem')),
            ];

            Log::warning('Groq transport request failed.', $diagnostics);

            throw new ChatCompletionProviderException($message, $timedOut ? 504 : 502, $exception, $diagnostics);
        }

        $this->lastHttpStatus = $response->status();

        if (! $response->successful()) {
            $status = $response->status();
            [$message, $httpStatus] = $this->providerFailure($status);
            $diagnostics = [
                'failure_type' => 'http_response',
                'http_status' => $status,
                'error_category' => match ($status) {
                    400 => 'request_rejected',
                    401 => 'invalid_api_key',
                    403 => 'account_access_denied',
                    404 => 'model_unavailable',
                    429 => 'rate_limit_or_quota',
                    default => $status >= 500 ? 'provider_unavailable' : 'provider_error',
                },
                'model' => $model,
                'timeout_seconds' => $timeout,
                'request_tool_choice' => is_string($payload['tool_choice'] ?? null) ? $payload['tool_choice'] : null,
                'request_tool_count' => is_array($payload['tools'] ?? null) ? count($payload['tools']) : 0,
                'request_message_count' => is_array($payload['messages'] ?? null) ? count($payload['messages']) : 0,
            ];
            $retryAfter = $this->safeRetryAfter($response->header('Retry-After'));

            if ($retryAfter !== null) {
                $diagnostics['retry_after'] = $retryAfter;
            }

            Log::warning('Groq API request failed.', $diagnostics);

            throw new ChatCompletionProviderException($message, $httpStatus, null, $diagnostics);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ChatCompletionProviderException(
                'Groq returned an invalid response. Try again later.',
                502,
                null,
                ['http_status' => $response->status(), 'error_category' => 'invalid_response'],
            );
        }

        return $body;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function testConnection(): array
    {
        $response = $this->complete([
            'messages' => [
                ['role' => 'user', 'content' => 'Call the tool with token atlas-tool-check.'],
            ],
            'temperature' => 0,
            'max_tokens' => 128,
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'atlas_tool_check',
                    'description' => 'Return the provided verification token without changing any data.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['token' => ['type' => 'string']],
                        'required' => ['token'],
                        'additionalProperties' => false,
                    ],
                ],
            ]],
            'tool_choice' => 'auto',
        ]);
        $choice = $response['choices'][0] ?? null;
        $assistantMessage = is_array($choice) && is_array($choice['message'] ?? null)
            ? $choice['message']
            : [];
        $toolCalls = $assistantMessage['tool_calls'] ?? null;
        $toolCall = is_array($toolCalls) ? ($toolCalls[0] ?? null) : null;
        $arguments = is_array($toolCall) && is_array($toolCall['function'] ?? null)
            ? json_decode((string) ($toolCall['function']['arguments'] ?? ''), true)
            : null;

        if (! is_array($toolCall)
            || ($toolCall['function']['name'] ?? null) !== 'atlas_tool_check'
            || ! is_array($arguments)
            || ($arguments['token'] ?? null) !== 'atlas-tool-check') {
            $diagnostics = [
                'failure_type' => 'tool_call_validation',
                'error_category' => ! array_key_exists('tool_calls', $assistantMessage)
                    ? 'tool_call_not_returned'
                    : 'tool_call_invalid',
                'http_status' => $this->lastHttpStatus,
                'finish_reason' => $this->safeIdentifier(is_array($choice) ? ($choice['finish_reason'] ?? null) : null),
                'tool_calls_present' => array_key_exists('tool_calls', $assistantMessage),
                'tool_call_count' => is_array($toolCalls) ? count($toolCalls) : 0,
            ];

            throw new ChatCompletionProviderException(
                'The configured Groq model did not complete the required function-calling smoke test. Review its tool-calling support and model access.',
                503,
                null,
                $diagnostics,
            );
        }

        return [
            'http_status' => $this->lastHttpStatus,
            'finish_reason' => $this->safeIdentifier(is_array($choice) ? ($choice['finish_reason'] ?? null) : null),
            'tool_calls_present' => array_key_exists('tool_calls', $assistantMessage),
            'tool_call_count' => is_array($toolCalls) ? count($toolCalls) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        if (isset($payload['max_tokens']) && ! isset($payload['max_completion_tokens'])) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        return $payload;
    }

    /**
     * @return array{string, int}
     */
    private function providerFailure(int $status): array
    {
        return match ($status) {
            401 => ['Groq rejected the configured API key. Verify GROQ_API_KEY on the server.', 503],
            403 => ['Groq denied access for the configured account or model. Check its account access settings.', 503],
            404 => ['The configured Groq model is unavailable. Verify GROQ_MODEL on the server.', 503],
            429 => ['Groq rate limits or organization quota are preventing Atlas AI from responding. Try again later.', 429],
            400 => ['Groq rejected the request. Check the configured model and tool-calling compatibility.', 422],
            default => $status >= 500
                ? ['Groq is temporarily unavailable. Try again later.', 503]
                : ['Groq rejected the request. Check the configured model and try again.', 502],
        };
    }

    private function safeIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $identifier = preg_replace('/[^A-Za-z0-9_. -]/', '', $value);
        $identifier = is_string($identifier) ? trim($identifier) : '';

        return $identifier !== '' ? substr($identifier, 0, 100) : null;
    }

    private function safeRetryAfter(?string $retryAfter): ?string
    {
        if (! is_string($retryAfter) || preg_match('/^[0-9]{1,6}$/', trim($retryAfter)) !== 1) {
            return null;
        }

        return trim($retryAfter).' seconds';
    }
}
