<?php

namespace App\Services\Callbacks;

use App\Models\CallbackAttempt;
use App\Models\Environment;
use App\Models\MockResponse;
use App\Services\Environments\EnvironmentContext;
use App\Services\Templates\ResponseTemplateEngine;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final readonly class CallbackDelivery
{
    public function __construct(private ResponseTemplateEngine $templates, private EnvironmentContext $environments) {}

    /**
     * All delays, network calls, and retries run in the dedicated callback worker.
     *
     * @param  array<string, mixed>  $requestContext
     */
    public function deliver(MockResponse $response, ?string $requestLogId, int $environmentId, array $requestContext, ?CallbackAttempt $resend = null): void
    {
        $url = '';
        $headers = [];
        $body = '';
        $method = (string) ($response->callback_method ?: 'POST');

        try {
            if ($resend !== null) {
                $url = $resend->target_url;
                $headers = $resend->resolved_headers;
                $body = $resend->resolved_body;
                $method = $resend->method;
            } else {
                $variables = $this->environments->variables(Environment::query()->findOrFail($environmentId));
                $context = ['env' => $variables, 'request' => $requestContext];
                $hash = hash('sha256', (string) $requestLogId);
                $url = (string) $this->renderValue((string) $response->callback_url, $response, $context, $hash);
                $headers = $this->renderValue($response->callback_headers ?? [], $response, $context, $hash);
                if ($headers instanceof \stdClass) {
                    $headers = (array) $headers;
                }
                $body = $this->templates->renderCallback((string) ($response->callback_body ?: '{}'), $response, $context, $hash)->json;
            }

            if (! is_array($headers) || ($headers !== [] && array_is_list($headers))) {
                throw new InvalidArgumentException('Callback headers must resolve to an object.');
            }
            $this->validateUrl($url);
            foreach ($headers as $name => $value) {
                if (! is_string($name) || preg_match('/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/D', $name) !== 1
                    || ! is_scalar($value) || preg_match('/[\r\n]/', (string) $value) === 1) {
                    throw new InvalidArgumentException('A callback header is invalid.');
                }
                $headers[$name] = (string) $value;
            }
        } catch (Throwable $exception) {
            $this->recordFailure($response, $requestLogId, $resend, $url, $method, $headers, $body, $exception);

            return;
        }

        if ($resend === null) {
            $minimum = max(0, min(30000, (int) $response->callback_delay_ms));
            $maximum = max($minimum, min(30000, (int) ($response->callback_delay_max_ms ?? $minimum)));
            $delay = random_int($minimum, $maximum);
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $attempts = $resend === null ? max(1, min(5, (int) $response->callback_retry)) : 1;
        $previousAttemptId = null;
        for ($number = 1; $number <= $attempts; $number++) {
            if ($number > 1) {
                usleep(max(0, min(30000, (int) $response->callback_backoff_ms)) * 1000);
            }

            $outboundHeaders = $this->signHeaders($response, $headers, $body);
            $attempt = CallbackAttempt::query()->create([
                'response_id' => $response->id,
                'request_log_id' => $requestLogId,
                'resend_of_id' => $resend?->id,
                'retry_of_id' => $previousAttemptId,
                'attempt_number' => $number,
                'target_url' => $url,
                'method' => $method,
                'resolved_headers' => $headers,
                'resolved_body' => $body,
                'status' => 'pending',
            ]);
            $previousAttemptId = $attempt->id;

            $started = hrtime(true);
            try {
                $result = Http::timeout(max(1, min(10000, (int) $response->callback_timeout_ms)) / 1000)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders($outboundHeaders)
                    ->send($method, $url, ['body' => $body]);

                $success = $result->status() >= 200 && $result->status() < 300;
                $attempt->update([
                    'status' => $success ? 'success' : 'failed',
                    'http_status' => $result->status(),
                    'duration_ms' => (int) round((hrtime(true) - $started) / 1000000),
                ]);
                if ($success) {
                    return;
                }
            } catch (Throwable $exception) {
                // Do not save exception messages: HTTP clients may include URL secrets.
                $attempt->update([
                    'status' => str_contains(strtolower($exception::class.' '.$exception->getMessage()), 'timed out')
                        || str_contains(strtolower($exception::class), 'timeout') ? 'timeout' : 'failed',
                    'duration_ms' => (int) round((hrtime(true) - $started) / 1000000),
                    'error' => $exception::class,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function renderValue(mixed $value, MockResponse $response, array $context, string $hash): mixed
    {
        $template = json_encode(['value' => $value], JSON_THROW_ON_ERROR);

        return $this->templates->renderCallback($template, $response, $context, $hash)->output->value;
    }

    public function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! isset($parts['host']) || filter_var($url, FILTER_VALIDATE_URL) === false
            || strlen($url) > 2048 || preg_match('/[\r\n]/', $url) === 1) {
            throw new InvalidArgumentException('Callback target must be a well-formed HTTP(S) URL.');
        }
    }

    /** @param array<string, string> $headers
     * @return array<string, string>
     */
    public function signHeaders(MockResponse $response, array $headers, string $body): array
    {
        $signatureHeader = (string) ($response->callback_signature_header ?: 'X-MockDeck-Signature');
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, $signatureHeader) === 0 || strcasecmp($name, 'X-MockDeck-Signature') === 0) {
                unset($headers[$name]);
            }
        }

        if ($response->callback_signing_enabled && $response->callback_signing_secret !== null && $response->callback_signing_secret !== '') {
            $headers[$signatureHeader] = hash_hmac('sha256', $body, $response->callback_signing_secret);
        }

        return $headers;
    }

    /** @param array<string, mixed> $headers */
    private function recordFailure(MockResponse $response, ?string $requestLogId, ?CallbackAttempt $resend, string $url, string $method, array $headers, string $body, Throwable $exception): void
    {
        CallbackAttempt::query()->create([
            'response_id' => $response->id,
            'request_log_id' => $requestLogId,
            'resend_of_id' => $resend?->id,
            'attempt_number' => 1,
            'target_url' => $url,
            'method' => $method,
            'resolved_headers' => $headers,
            'resolved_body' => $body,
            'status' => 'failed',
            'error' => $exception::class,
        ]);
    }
}
