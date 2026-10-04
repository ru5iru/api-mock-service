<?php

namespace App\Services\Verification;

use App\Services\Response\RequestPredicateEvaluator;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Bounded verification evidence, independent of the request-log retention tail. */
final class CallDigestRecorder
{
    public const CAPACITY = 20;

    public const MAX_FIELDS = 64;

    public const MAX_VALUE_BYTES = 256;

    public const MAX_BODY_BYTES = 65536;

    public function __construct(private readonly RequestPredicateEvaluator $predicates) {}

    public function capture(Request $request, DateTimeInterface $matchedAt): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            // Rule evaluation uses header(), i.e. the first value, not a joined list.
            $headers[strtolower($name)] = $values[0] ?? null;
        }
        $body = $request->getContent();
        $json = strlen($body) <= self::MAX_BODY_BYTES ? json_decode($body, true) : null;
        $bodyDigest = ['sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'complete' => strlen($body) <= self::MAX_BODY_BYTES, 'fields' => []];
        if (is_array($json)) {
            $paths = [];
            $complete = true;
            $this->jsonPaths($json, '', $paths, $complete);
            foreach ($paths as $path) {
                // Resolve against the original tree to preserve Arr's direct-key
                // precedence for JSON keys containing dots, exactly as rules do.
                if (Arr::has($json, $path)) {
                    $bodyDigest['fields'][$path] = $this->field($path, Arr::get($json, $path));
                }
            }
            $bodyDigest['complete'] = $complete;
        }

        return [
            'matched_at' => $matchedAt->format(DATE_ATOM),
            'method' => mb_strcut(mb_scrub($request->method(), 'UTF-8'), 0, 32, 'UTF-8'),
            'path' => mb_strcut(mb_scrub($request->getPathInfo(), 'UTF-8'), 0, 2048, 'UTF-8'),
            'header_digest' => $this->projection($headers),
            'query_digest' => $this->projection($request->query()),
            'body_digest_or_snippet' => $bodyDigest,
        ];
    }

    private function projection(array $values): array
    {
        $result = ['sha256' => hash('sha256', json_encode($values, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)), 'complete' => true, 'fields' => []];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if (count($result['fields']) >= self::MAX_FIELDS || strlen($name) > 255 || ! mb_check_encoding($name, 'UTF-8')) {
                $result['complete'] = false;

                continue;
            }
            $result['fields'][$name] = $this->field($name, $value);
        }

        return $result;
    }

    private function jsonPaths(array $node, string $prefix, array &$paths, bool &$complete, int $depth = 0): void
    {
        foreach ($node as $name => $value) {
            $path = $prefix === '' ? (string) $name : $prefix.'.'.$name;
            if (count($paths) >= self::MAX_FIELDS || strlen($path) > 255 || $depth >= 16) {
                $complete = false;

                return;
            }
            $paths[] = $path;
            if (is_array($value)) {
                $this->jsonPaths($value, $path, $paths, $complete, $depth + 1);
            }
        }
    }

    private function field(string $name, mixed $value): array
    {
        if (! is_scalar($value)) {
            return ['scalar' => false];
        }
        $string = $this->predicates->scalarString($value);
        $result = ['scalar' => true, 'sha256' => hash('sha256', $string)];
        if ($this->sensitive($name)) {
            $result['omitted'] = 'sensitive';
        } elseif (strlen($string) > self::MAX_VALUE_BYTES || ! mb_check_encoding($string, 'UTF-8')) {
            $result['omitted'] = 'size_or_encoding';
        } else {
            $result['value'] = $string;
        }

        return $result;
    }

    private function sensitive(string $name): bool
    {
        return preg_match('/(?:authorization|cookie|password|passwd|secret|token|api[-_]?key|credential)/i', $name) === 1;
    }
}
