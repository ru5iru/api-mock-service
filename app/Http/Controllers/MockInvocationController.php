<?php

namespace App\Http\Controllers;

use App\Services\Callbacks\CallbackDispatcher;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\IncomingRequestFactory;
use App\Services\Environments\EnvironmentContext;
use App\Services\Logging\MockRequestLogger;
use App\Services\Matching\EndpointMatch;
use App\Services\Matching\EndpointMatcher;
use App\Services\Response\FaultConfigurationValidator;
use App\Services\Response\FaultInjectionService;
use App\Services\Response\FaultPlan;
use App\Services\Response\ResponseHeaderPolicy;
use App\Services\Response\ResponseSelectionService;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use App\Services\Templates\TemplateRenderException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Catch-all invocation boundary: reconstruct, generate five matching variants,
 * perform hash/fallback lookup, select a response, delay, return, and log.
 */
final class MockInvocationController extends Controller
{
    private ?string $selectionReason = null;

    private ?FaultPlan $faultPlan = null;

    private bool $faultApplied = false;

    /** @var array<string, mixed>|null */
    private ?array $templateCapture = null;

    public function __construct(
        private readonly IncomingRequestFactory $requestFactory,
        private readonly EndpointMatcher $matcher,
        private readonly ResponseSelectionService $selector,
        private readonly MockRequestLogger $logger,
        private readonly CurlHasher $hasher,
        private readonly ResponseTemplateEngine $templates,
        private readonly EnvironmentContext $environments,
        private readonly CallbackDispatcher $callbacks,
        private readonly FaultInjectionService $faults,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->selectionReason = null;
        $this->faultPlan = null;
        $this->faultApplied = false;
        $this->templateCapture = null;
        $startedAt = hrtime(true);
        $match = null;
        $selected = null;
        $statusCode = 500;
        $delayMs = 0;
        $templated = false;
        $renderMs = null;
        $requestId = $this->requestId($request);
        $effectiveUrl = $request->fullUrl();

        try {
            $captured = $this->requestFactory->fromRequest($request);
            $effectiveUrl = $captured->url;
            $match = $this->matcher->match($captured);

            if ($match === null) {
                $statusCode = 404;
                $response = response()->json([
                    'error' => 'No mock configured for this request',
                    'method' => $captured->method,
                    'url' => $captured->url,
                ], $statusCode);

                $response->headers->set('X-Request-ID', $requestId);
                $this->writeLog($request, $effectiveUrl, $requestId, $startedAt, null, null, $statusCode, 0);

                return $response;
            }

            // Wave 2 integration: match/capture -> select/count -> decide fault ->
            // render with shared context -> apply output fault -> send/log.
            $request->attributes->set('mockdeck.path_parameters', $match->pathParameters);
            $environment = $this->environments->active();
            $selection = $this->selector->select($match->endpoint, $request, $environment);
            $this->selectionReason = $selection->reason;
            $selected = $selection->response;
            if ($selected === null) {
                $exhausted = $selection->reason === 'sequence_exhausted';
                $statusCode = $exhausted ? 404 : 500;
                $payload = $exhausted
                    ? ['error' => 'No mock configured for this request', 'method' => $captured->method, 'url' => $captured->url, 'reason' => 'sequence_exhausted']
                    : ($selection->reason === 'no_responses'
                        ? ['error' => 'The matched mock has no configured responses', 'endpoint_id' => $match->endpoint->id]
                        : ['error' => 'The matched mock has invalid response selection configuration', 'endpoint_id' => $match->endpoint->id, 'reason' => $selection->reason]);
                $response = response()->json($payload, $statusCode);
                $response->headers->set('X-Request-ID', $requestId);
                $this->writeLog($request, $effectiveUrl, $requestId, $startedAt, $match, null, $statusCode, 0);

                return $response;
            }

            $this->faultPlan = $this->faults->decide($selected);
            $statusCode = $selected->status_code;
            $delayMs = min(max(0, $selected->delay_ms), (int) config('mock.max_delay_ms', 30000));

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $this->faults->pause($this->faultPlan);
            $delayMs += $this->faultPlan?->delayMs ?? 0;
            $this->faultApplied = $this->faultPlan !== null && in_array($this->faultPlan->type, ['delay', 'timeout'], true);

            $headers = $selected->headers ?? [];
            $body = (string) ($selected->body ?? '');
            if ($selected->body_mode === 'template') {
                $templated = true;
                $variant = $match->variant ?? 'V1';
                // V6 canonicalizes excluded fields and the stored path pattern.
                // A successful match guarantees the remaining canonical bytes agree.
                $requestHash = $variant === 'V6'
                    ? hash('sha256', $match->endpoint->normalized_curl)
                    : $this->hasher->variants($captured)[$variant]->hash;
                $context = null;
                $contexts = app(TemplateContext::class);
                if ($contexts->usesContext((string) $selected->template)) {
                    $this->templateCapture = $contexts->capture($request, $requestId);
                    $context = $contexts->build($this->templateCapture, $environment);
                }
                $rendered = $this->templates->renderResponse($selected, $requestHash, $context);
                $body = $rendered->json;
                $renderMs = $rendered->renderMs;

                $hasContentType = collect(array_keys($headers))->contains(
                    static fn (string|int $name): bool => strcasecmp((string) $name, 'Content-Type') === 0,
                );
                if (! $hasContentType) {
                    $headers['Content-Type'] = 'application/json';
                }
            }

            $body = $this->faults->transformBody($body, $this->faultPlan, FaultConfigurationValidator::contentType(['headers' => $headers, 'body_mode' => $selected->body_mode]));
            if ($this->faultPlan !== null && in_array($this->faultPlan->type, ['malformed_body', 'truncated_body'], true)) {
                $this->faultApplied = true;
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Content-Length') === 0) {
                        $headers[$name] = (string) strlen($body);
                    }
                }
            }
            $response = response($body, $statusCode);
            $headers = app(ResponseHeaderPolicy::class)->forServing($headers, ($match->endpoint->external_source['type'] ?? null) === 'postman');
            foreach ($headers as $name => $value) {
                $response->headers->set((string) $name, (string) $value);
            }

            $response->headers->set('X-Request-ID', $requestId);
            $this->writeLog($request, $effectiveUrl, $requestId, $startedAt, $match, $selected->id, $statusCode, $delayMs, null, $templated, $renderMs);

            try {
                $this->callbacks->afterResponse($selected, $request, $requestId, $this->environments->active()->id);
            } catch (Throwable $exception) {
                // Callback scheduling must not alter the already-built primary response.
                error_log('MockDeck callback scheduling failed: '.$exception::class);
            }

            return $response;
        } catch (TemplateRenderException $exception) {
            $statusCode = 500;
            $this->writeLog(
                $request,
                $effectiveUrl,
                $requestId,
                $startedAt,
                $match,
                $selected?->id,
                $statusCode,
                $delayMs,
                $exception::class,
                true,
                $renderMs,
                $exception->issueCode,
                $exception->templatePath,
                $exception->token,
            );

            $response = response()->json([
                'error' => 'template_render_failed',
                'path' => $exception->templatePath,
                'token' => $exception->token,
                'message' => $exception->getMessage(),
            ], $statusCode);
            $response->headers->set('X-MockDeck-Template-Error', '1');
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } catch (InvalidArgumentException $exception) {
            $statusCode = 400;
            $this->writeLog(
                $request,
                $effectiveUrl,
                $requestId,
                $startedAt,
                $match,
                $selected?->id,
                $statusCode,
                $delayMs,
                $exception::class,
            );

            $response = response()->json([
                'error' => 'The request could not be normalized',
                'detail' => $exception->getMessage(),
            ], $statusCode);
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } catch (Throwable $exception) {
            $this->writeLog(
                $request,
                $effectiveUrl,
                $requestId,
                $startedAt,
                $match,
                $selected?->id,
                $statusCode,
                $delayMs,
                $exception::class,
            );

            throw $exception;
        }
    }

    private function writeLog(
        Request $request,
        string $effectiveUrl,
        string $requestId,
        int $startedAt,
        ?EndpointMatch $match,
        ?int $responseId,
        int $statusCode,
        int $delayMs,
        ?string $error = null,
        bool $templated = false,
        ?float $renderMs = null,
        ?string $templateError = null,
        ?string $templatePath = null,
        ?string $templateToken = null,
    ): void {
        $context = [
            'request_id' => $requestId,
            'method' => strtoupper($request->method()),
            'url' => $effectiveUrl,
            'matched' => $match !== null,
            'match_tier' => $match?->tier ?? 'none',
            'matched_variant' => $match?->variant,
            'endpoint_id' => $match?->endpoint->id,
            'response_id' => $responseId,
            'selection_mode' => $match?->endpoint->selection_mode,
            'selection_reason' => $this->selectionReason ?? 'no_endpoint_matched',
            'status_code' => $statusCode,
            'delay_ms' => $delayMs,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'environment' => $this->environments->active()->name,
            'environment_id' => $this->environments->active()->id,
        ];

        if ($this->faultApplied && $this->faultPlan !== null) {
            $context['fault_applied'] = $this->faultPlan->type;
            $context['fault_delay_ms'] = $this->faultPlan->delayMs;
        }

        if (($this->templateCapture['body_context_omitted'] ?? false) === true) {
            $context['context_warning'] = 'request_body_context_omitted';
            $context['body_bytes'] = $this->templateCapture['body_bytes'];
            $context['context_limit_bytes'] = TemplateContext::MAX_BODY_BYTES;
        }
        if ($error !== null) {
            $context['error'] = $error;
        }
        if ($templated) {
            $context['templated'] = true;
            $context['render_ms'] = $renderMs;
        }
        if ($templateError !== null) {
            $context['template_error'] = $templateError;
            $context['template_path'] = $templatePath;
            $context['template_token'] = $templateToken;
        }

        $this->logger->write($context);
    }

    private function requestId(Request $request): string
    {
        $provided = trim((string) $request->headers->get('X-Request-ID'));

        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $provided) === 1
            ? $provided
            : (string) Str::uuid();
    }
}
