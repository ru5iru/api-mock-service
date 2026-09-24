<?php

namespace App\Http\Controllers;

use App\Services\Curl\IncomingRequestFactory;
use App\Services\Curl\CurlHasher;
use App\Services\Logging\MockRequestLogger;
use App\Services\Matching\EndpointMatch;
use App\Services\Matching\EndpointMatcher;
use App\Services\Response\ResponseSelectorInterface;
use App\Services\Templates\ResponseTemplateEngine;
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
    public function __construct(
        private readonly IncomingRequestFactory $requestFactory,
        private readonly EndpointMatcher $matcher,
        private readonly ResponseSelectorInterface $selector,
        private readonly MockRequestLogger $logger,
        private readonly CurlHasher $hasher,
        private readonly ResponseTemplateEngine $templates,
    ) {}

    public function __invoke(Request $request): Response
    {
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

            if ($match->endpoint->responses->isEmpty()) {
                $statusCode = 500;
                $response = response()->json([
                    'error' => 'The matched mock has no configured responses',
                    'endpoint_id' => $match->endpoint->id,
                ], $statusCode);

                $response->headers->set('X-Request-ID', $requestId);
                $this->writeLog($request, $effectiveUrl, $requestId, $startedAt, $match, null, $statusCode, 0);

                return $response;
            }

            $selected = $this->selector->select($match->endpoint->responses);
            $statusCode = $selected->status_code;
            $delayMs = min(max(0, $selected->delay_ms), (int) config('mock.max_delay_ms', 30000));

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $headers = $selected->headers ?? [];
            $body = (string) ($selected->body ?? '');
            if ($selected->body_mode === 'template') {
                $templated = true;
                $variant = $match->variant ?? 'V1';
                $requestHash = $this->hasher->variants($captured)[$variant]->hash;
                $rendered = $this->templates->renderResponse($selected, $requestHash);
                $body = $rendered->json;
                $renderMs = $rendered->renderMs;

                $hasContentType = collect(array_keys($headers))->contains(
                    static fn (string|int $name): bool => strcasecmp((string) $name, 'Content-Type') === 0,
                );
                if (! $hasContentType) {
                    $headers['Content-Type'] = 'application/json';
                }
            }

            $response = response($body, $statusCode);
            foreach ($headers as $name => $value) {
                $response->headers->set((string) $name, (string) $value);
            }

            $response->headers->set('X-Request-ID', $requestId);
            $this->writeLog($request, $effectiveUrl, $requestId, $startedAt, $match, $selected->id, $statusCode, $delayMs, null, $templated, $renderMs);

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
            'status_code' => $statusCode,
            'delay_ms' => $delayMs,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ];

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
