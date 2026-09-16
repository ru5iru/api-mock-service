<?php

namespace App\Http\Controllers;

use App\Services\Curl\IncomingRequestFactory;
use App\Services\Logging\MockRequestLogger;
use App\Services\Matching\EndpointMatch;
use App\Services\Matching\EndpointMatcher;
use App\Services\Response\ResponseSelectorInterface;
use Illuminate\Http\Request;
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
    ) {}

    public function __invoke(Request $request): Response
    {
        $startedAt = hrtime(true);
        $match = null;
        $selected = null;
        $statusCode = 500;
        $delayMs = 0;

        try {
            $captured = $this->requestFactory->fromRequest($request);
            $match = $this->matcher->match($captured);

            if ($match === null) {
                $statusCode = 404;
                $response = response()->json([
                    'error' => 'No mock configured for this request',
                    'method' => $captured->method,
                    'url' => $captured->url,
                ], $statusCode);

                $this->writeLog($request, $startedAt, null, null, $statusCode, 0);

                return $response;
            }

            if ($match->endpoint->responses->isEmpty()) {
                $statusCode = 500;
                $response = response()->json([
                    'error' => 'The matched mock has no configured responses',
                    'endpoint_id' => $match->endpoint->id,
                ], $statusCode);

                $this->writeLog($request, $startedAt, $match, null, $statusCode, 0);

                return $response;
            }

            $selected = $this->selector->select($match->endpoint->responses);
            $statusCode = $selected->status_code;
            $delayMs = min(max(0, $selected->delay_ms), (int) config('mock.max_delay_ms', 30000));

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $response = response((string) ($selected->body ?? ''), $statusCode);
            foreach ($selected->headers ?? [] as $name => $value) {
                $response->headers->set((string) $name, (string) $value);
            }

            $this->writeLog($request, $startedAt, $match, $selected->id, $statusCode, $delayMs);

            return $response;
        } catch (Throwable $exception) {
            $this->writeLog(
                $request,
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
        int $startedAt,
        ?EndpointMatch $match,
        ?int $responseId,
        int $statusCode,
        int $delayMs,
        ?string $error = null,
    ): void {
        $context = [
            'method' => strtoupper($request->method()),
            'url' => $request->getRequestUri(),
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

        $this->logger->write($context);
    }
}
