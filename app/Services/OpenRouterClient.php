<?php

namespace App\Services;

use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterClient implements ChatCompletionClient
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    private ?int $lastHttpStatus = null;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function complete(array $payload): array
    {
        $apiKey = config('services.openrouter.key');
        $model = config('services.openrouter.model');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new OpenRouterProviderException('Atlas AI is not configured. Set OPENROUTER_API_KEY on the server.', 503);
        }

        if (! is_string($model) || trim($model) === '') {
            throw new OpenRouterProviderException('Atlas AI has no model configured. Set OPENROUTER_MODEL on the server.', 503);
        }

        if (in_array(strtolower(trim($model)), ['openrouter/free', 'openrouter/auto'], true)) {
            throw new OpenRouterProviderException(
                'OPENROUTER_MODEL must be a specific model ID. The configured router can select different models; choose a fixed model that supports tool calling.',
                503,
            );
        }

        $timeout = max(1, (int) config('services.openrouter.timeout', 25));
        $caBundlePath = base_path('certs/cacert.pem');
        $transferDiagnostics = [];
        $startedAt = microtime(true);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withOptions([
                    'verify' => $caBundlePath,
                    'on_stats' => function (TransferStats $stats) use (&$transferDiagnostics): void {
                        $transferDiagnostics = $this->transferDiagnostics($stats);
                    },
                ])
                ->connectTimeout(5)
                ->timeout($timeout)
                ->withToken($apiKey)
                ->post(self::ENDPOINT, ['model' => $model] + $payload);
        } catch (ConnectionException $exception) {
            $timedOut = preg_match('/timed?\s*out|timeout|cURL error 28/i', $exception->getMessage()) === 1;
            $message = $timedOut
                ? 'OpenRouter did not respond before the request timed out. Try again later.'
                : 'OpenRouter could not be reached. Check the server connection and try again.';
            $diagnostics = $this->requestDiagnostics($model, $timeout, $caBundlePath, $transferDiagnostics);
            $diagnostics['failure_type'] = $this->transportFailureType($exception, $transferDiagnostics, $timedOut);
            $diagnostics['elapsed_seconds'] = round(microtime(true) - $startedAt, 2);

            Log::warning('OpenRouter transport request failed.', [
                ...$diagnostics,
            ]);

            throw new OpenRouterProviderException($message, $timedOut ? 504 : 502, $exception, $diagnostics);
        }

        $this->lastHttpStatus = $response->status();

        if (! $response->successful()) {
            $status = $response->status();
            $responseBody = $response->json();
            $providerErrorResponse = is_array($responseBody) ? ($responseBody['error'] ?? null) : null;
            $providerError = is_array($providerErrorResponse) ? $providerErrorResponse : [];
            $metadata = is_array($providerError['metadata'] ?? null)
                ? $providerError['metadata']
                : [];
            $providerCode = $this->safeProviderIdentifier($providerError['code'] ?? null);
            $providerErrorType = $this->safeProviderIdentifier($metadata['error_type'] ?? null);
            $providerName = $this->safeProviderIdentifier(
                $metadata['provider_name'] ?? $metadata['provider'] ?? $providerError['provider_name'] ?? null,
            );
            $providerMessage = is_string($providerError['message'] ?? null)
                ? $providerError['message']
                : '';
            $rateLimitSource = $status === 429
                ? $this->rateLimitSource($providerErrorType, $providerName, $providerCode, $providerMessage)
                : null;
            [$message, $httpStatus] = $this->providerFailure(
                $status,
                $providerCode,
                strtolower($providerMessage),
                $rateLimitSource,
            );
            $diagnostics = $this->requestDiagnostics($model, $timeout, $caBundlePath, $transferDiagnostics);
            $diagnostics['failure_type'] = 'http_response';
            $diagnostics['http_status'] = $status;
            $diagnostics['elapsed_seconds'] = round(microtime(true) - $startedAt, 2);
            $diagnostics += $this->requestShapeDiagnostics($payload);
            $diagnostics += $this->responseShapeDiagnostics(is_array($responseBody) ? $responseBody : []);

            if ($providerMessage !== '') {
                $diagnostics['provider_error_message'] = $this->safeProviderMessage($providerMessage, $apiKey, $payload);
            }

            if ($providerErrorType !== null) {
                $diagnostics['metadata_error_type'] = $providerErrorType;
                $diagnostics['error_category'] = $providerErrorType;
            } elseif ($providerCode !== null) {
                $diagnostics['error_category'] = $providerCode;
            }

            if ($providerCode !== null) {
                $diagnostics['provider_code'] = $providerCode;
            }

            if ($providerName !== null) {
                $diagnostics['provider_name'] = $providerName;
            }

            $retryAfter = $this->safeRetryAfter($response->header('Retry-After'));

            if ($retryAfter !== null) {
                $diagnostics['retry_after'] = $retryAfter;
            }

            if ($rateLimitSource !== null) {
                $diagnostics['rate_limit_source'] = $rateLimitSource;
            }

            Log::warning('OpenRouter API request failed.', [
                'model' => $model,
                'failure_type' => 'http_response',
                'http_status' => $status,
                'rate_limit_source' => $rateLimitSource,
                'elapsed_seconds' => $diagnostics['elapsed_seconds'],
                'error_category' => $diagnostics['error_category'] ?? null,
                'provider_code' => $providerCode,
                'provider_error_message' => $diagnostics['provider_error_message'] ?? null,
                'finish_reason' => $diagnostics['finish_reason'] ?? null,
                'response_tool_calls_present' => $diagnostics['response_tool_calls_present'] ?? false,
                'response_tool_call_count' => $diagnostics['response_tool_call_count'] ?? 0,
                'request_tool_choice' => $diagnostics['request_tool_choice'] ?? null,
                'request_tool_count' => $diagnostics['request_tool_count'] ?? 0,
                'request_message_count' => $diagnostics['request_message_count'] ?? 0,
            ]);

            throw new OpenRouterProviderException($message, $httpStatus, null, $diagnostics);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new OpenRouterProviderException('OpenRouter returned an invalid response. Try again later.', 502);
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
            'max_tokens' => 256,
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
            'tool_choice' => 'required',
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
            $category = ! array_key_exists('tool_calls', $assistantMessage)
                ? 'tool_call_not_returned'
                : (! is_array($toolCalls) || ! is_array($toolCall)
                    ? 'tool_call_invalid'
                    : (($toolCall['function']['name'] ?? null) !== 'atlas_tool_check'
                        ? 'unexpected_tool_name'
                        : 'tool_arguments_invalid'));
            $diagnostics = [
                'failure_type' => 'tool_call_validation',
                'error_category' => $category,
                'http_status' => 200,
                'finish_reason' => $this->safeProviderIdentifier(
                    is_array($choice) ? ($choice['finish_reason'] ?? null) : null,
                ),
                'tool_calls_present' => array_key_exists('tool_calls', $assistantMessage),
            ];

            if (is_array($toolCalls)) {
                $diagnostics['tool_call_count'] = count($toolCalls);
            }

            throw new OpenRouterProviderException(
                'The configured OpenRouter model did not complete the required function-calling smoke test. Review the diagnostic details and model tool-call behavior.',
                503,
                null,
                $diagnostics,
            );
        }

        return [
            'http_status' => $this->lastHttpStatus,
            'finish_reason' => $this->safeProviderIdentifier(
                is_array($choice) ? ($choice['finish_reason'] ?? null) : null,
            ),
            'tool_calls_present' => array_key_exists('tool_calls', $assistantMessage),
            'tool_call_count' => is_array($toolCalls) ? count($toolCalls) : 0,
        ];
    }

    /**
     * @return array<string, bool|float|int|null>
     */
    private function transferDiagnostics(TransferStats $stats): array
    {
        $handlerStats = $stats->getHandlerStats();
        $diagnostics = [
            'response_received' => $stats->hasResponse(),
        ];

        foreach ([
            'connect_time',
            'appconnect_time',
            'starttransfer_time',
            'total_time',
            'http_code',
            'ssl_verify_result',
            'errno',
        ] as $key) {
            $value = $handlerStats[$key] ?? null;

            if (is_float($value) || is_int($value)) {
                $diagnostics[$key] = $value;
            }
        }

        $handlerError = $stats->getHandlerErrorData();

        if (is_int($handlerError)) {
            $diagnostics['errno'] = $handlerError;
        }

        return $diagnostics;
    }

    /**
     * @param  array<string, bool|float|int|null>  $transferDiagnostics
     * @return array<string, bool|float|int|string|null>
     */
    private function requestDiagnostics(string $model, int $timeout, string $caBundlePath, array $transferDiagnostics): array
    {
        return [
            'model' => $model,
            'timeout_seconds' => $timeout,
            'connect_timeout_seconds' => 5,
            'ca_bundle' => 'certs/cacert.pem',
            'ca_bundle_exists' => is_file($caBundlePath),
            'tls_verification_enabled' => true,
            ...$transferDiagnostics,
        ];
    }

    /**
     * @param  array<string, bool|float|int|null>  $transferDiagnostics
     */
    private function transportFailureType(ConnectionException $exception, array $transferDiagnostics, bool $timedOut): string
    {
        $curlError = $transferDiagnostics['errno'] ?? null;
        $sslVerifyResult = $transferDiagnostics['ssl_verify_result'] ?? null;

        if (in_array($curlError, [35, 51, 58, 60, 77], true)
            || (is_int($sslVerifyResult) && $sslVerifyResult !== 0)) {
            return 'tls_or_certificate_failure';
        }

        if ($timedOut) {
            if (isset($transferDiagnostics['starttransfer_time']) && $transferDiagnostics['starttransfer_time'] > 0) {
                return 'response_body_timeout';
            }

            if (isset($transferDiagnostics['appconnect_time']) && $transferDiagnostics['appconnect_time'] > 0) {
                return 'response_timeout_after_tls';
            }

            if (isset($transferDiagnostics['connect_time']) && $transferDiagnostics['connect_time'] > 0) {
                return 'connect_or_tls_timeout';
            }

            return 'timeout_phase_unavailable';
        }

        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'certificate') || str_contains($message, 'ssl')) {
            return 'tls_or_certificate_failure';
        }

        return 'connection_failure';
    }

    private function safeProviderIdentifier(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $identifier = preg_replace('/[^A-Za-z0-9_. -]/', '', (string) $value);
        $identifier = is_string($identifier) ? trim($identifier) : '';

        return $identifier !== '' ? substr($identifier, 0, 100) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, bool|int|string|null>
     */
    private function requestShapeDiagnostics(array $payload): array
    {
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $tools = is_array($payload['tools'] ?? null) ? $payload['tools'] : [];
        $toolCallCount = 0;
        $messageRoles = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = $this->safeProviderIdentifier($message['role'] ?? null);

            if ($role !== null) {
                $messageRoles[] = $role;
            }

            if (is_array($message['tool_calls'] ?? null)) {
                $toolCallCount += count($message['tool_calls']);
            }
        }

        $toolChoice = $payload['tool_choice'] ?? null;

        return [
            'request_tool_choice' => is_string($toolChoice) ? $this->safeProviderIdentifier($toolChoice) : null,
            'request_tool_count' => count($tools),
            'request_max_tokens' => is_int($payload['max_tokens'] ?? null) ? $payload['max_tokens'] : null,
            'request_message_count' => count($messages),
            'request_message_roles' => implode(',', $messageRoles),
            'request_tool_call_count' => $toolCallCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, bool|int|string|null>
     */
    private function responseShapeDiagnostics(array $body): array
    {
        $choice = $body['choices'][0] ?? null;
        $message = is_array($choice) && is_array($choice['message'] ?? null)
            ? $choice['message']
            : [];
        $toolCalls = $message['tool_calls'] ?? null;

        return [
            'finish_reason' => $this->safeProviderIdentifier(is_array($choice) ? ($choice['finish_reason'] ?? null) : null),
            'response_tool_calls_present' => array_key_exists('tool_calls', $message),
            'response_tool_call_count' => is_array($toolCalls) ? count($toolCalls) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function safeProviderMessage(string $message, string $apiKey, array $payload): ?string
    {
        $sensitiveTerms = [$apiKey];
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

        foreach ($messages as $requestMessage) {
            if (! is_array($requestMessage) || ($requestMessage['role'] ?? null) === 'system') {
                continue;
            }

            $content = $requestMessage['content'] ?? null;

            if (is_string($content) && $content !== '') {
                $sensitiveTerms[] = $content;
                $contentTerms = preg_split('/[^\p{L}\p{N}_-]+/u', $content, -1, PREG_SPLIT_NO_EMPTY);

                if (is_array($contentTerms)) {
                    $sensitiveTerms = [...$sensitiveTerms, ...array_filter($contentTerms, fn (string $term): bool => mb_strlen($term) > 2)];
                }
            }

            foreach (is_array($requestMessage['tool_calls'] ?? null) ? $requestMessage['tool_calls'] : [] as $toolCall) {
                if (is_array($toolCall) && is_string($toolCall['id'] ?? null)) {
                    $sensitiveTerms[] = $toolCall['id'];
                }

                $arguments = is_array($toolCall) && is_array($toolCall['function'] ?? null)
                    ? $toolCall['function']['arguments'] ?? null
                    : null;

                if (is_string($arguments)) {
                    $decodedArguments = json_decode($arguments, true);
                    $this->collectSensitiveValues($decodedArguments, $sensitiveTerms);
                }
            }
        }

        usort($sensitiveTerms, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        foreach (array_unique(array_filter($sensitiveTerms, fn (string $term): bool => mb_strlen($term) > 2)) as $term) {
            $message = str_ireplace($term, '[redacted]', $message);
        }

        $message = strip_tags($message);
        $message = preg_replace('/\bBearer\s+\S+/i', '[redacted]', $message) ?? '';
        $message = preg_replace('/\b(?:sk-or-v1|sk)-[A-Za-z0-9_-]{8,}\b/i', '[redacted]', $message) ?? '';
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted]', $message) ?? '';
        $message = preg_replace('/\b\d+(?:[,.]\d+)*\b/', '[redacted]', $message) ?? '';
        $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message) ?? '';
        $message = trim($message);

        return $message !== '' ? substr($message, 0, 500) : null;
    }

    /**
     * @param  list<string>  $sensitiveTerms
     */
    private function collectSensitiveValues(mixed $value, array &$sensitiveTerms): void
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $sensitiveTerms[] = (string) $value;

            if (is_string($value)) {
                $terms = preg_split('/[^\p{L}\p{N}_-]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

                if (is_array($terms)) {
                    $sensitiveTerms = [...$sensitiveTerms, ...array_filter($terms, fn (string $term): bool => mb_strlen($term) > 2)];
                }
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                $this->collectSensitiveValues($nestedValue, $sensitiveTerms);
            }
        }
    }

    private function safeRetryAfter(?string $retryAfter): ?string
    {
        $retryAfter = trim((string) $retryAfter);

        if (preg_match('/^\d{1,7}$/', $retryAfter) === 1) {
            return $retryAfter.' seconds';
        }

        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $retryAfter);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format(DATE_RFC7231) === $retryAfter) {
            return $date->format(DATE_RFC7231);
        }

        return null;
    }

    private function rateLimitSource(
        ?string $errorType,
        ?string $providerName,
        ?string $providerCode,
        string $providerMessage,
    ): string {
        $errorType = strtolower((string) $errorType);
        $providerCode = strtolower((string) $providerCode);
        $providerMessage = strtolower($providerMessage);

        if (str_contains($errorType, 'provider')
            || str_contains($errorType, 'upstream')
            || $providerName !== null) {
            return 'upstream_provider';
        }

        if (str_contains($errorType, 'account')
            || str_contains($errorType, 'user')
            || str_contains($errorType, 'openrouter')
            || preg_match('/account|credit|free-models-per|openrouter/i', $providerCode.' '.$providerMessage) === 1) {
            return 'openrouter_account';
        }

        return 'undetermined';
    }

    /**
     * @return array{string, int}
     */
    private function providerFailure(
        int $status,
        ?string $providerCode,
        string $providerMessage,
        ?string $rateLimitSource = null,
    ): array {
        if (in_array($status, [401, 403], true)) {
            return ['OpenRouter rejected the configured API key. Verify OPENROUTER_API_KEY on the server.', 503];
        }

        if ($status === 402) {
            return ['OpenRouter account billing or credits need attention before Atlas AI can respond.', 503];
        }

        if ($status === 404
            || str_contains(strtolower((string) $providerCode), 'model')
            || str_contains($providerMessage, 'model not found')
            || str_contains($providerMessage, 'no endpoints found')) {
            return ['The configured OpenRouter model is unavailable. Verify OPENROUTER_MODEL on the server.', 503];
        }

        if ($status === 429) {
            if ($rateLimitSource === 'openrouter_account') {
                return ['OpenRouter account limits are preventing Atlas AI from responding. Check your account usage and credits.', 429];
            }

            if ($rateLimitSource === 'upstream_provider') {
                return ['The upstream provider is rate limiting Atlas AI. Try again later or select another supported model.', 429];
            }

            return ['OpenRouter is rate limiting Atlas right now. Try again in a moment.', 429];
        }

        if ($status === 408) {
            return ['OpenRouter did not respond before the request timed out. Try again later.', 504];
        }

        if ($status >= 500) {
            return ['OpenRouter is temporarily unavailable. Try again later.', 503];
        }

        return ['OpenRouter rejected the request. Check the configured model and try again.', 502];
    }
}
