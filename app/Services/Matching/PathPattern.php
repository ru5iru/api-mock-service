<?php

namespace App\Services\Matching;

use InvalidArgumentException;

/** Whole-segment wildcards only: no regex syntax, partial segments, or greedy paths. */
final class PathPattern
{
    /** @return list<string> */
    public function validate(string $pattern): array
    {
        if (! str_starts_with($pattern, '/') || strlen($pattern) > 8192) {
            throw new InvalidArgumentException('A path pattern must start with / and be at most 8192 characters.');
        }
        $names = [];
        foreach (explode('/', $pattern) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $segment, $matches) === 1) {
                if (in_array($matches[1], $names, true)) {
                    throw new InvalidArgumentException('Path parameter names must be unique.');
                }
                $names[] = $matches[1];
            } elseif (str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new InvalidArgumentException('Use a whole segment such as {id}; parameter names use letters, numbers and underscores and cannot start with a number.');
            }
        }

        return $names;
    }

    /** @return array<string, string>|null */
    public function capture(string $pattern, string $path): ?array
    {
        $this->validate($pattern);
        $expected = explode('/', $pattern);
        $actual = explode('/', $path);
        if (count($expected) !== count($actual)) {
            return null;
        }
        $captures = [];
        foreach ($expected as $index => $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $segment, $matches) === 1) {
                if ($actual[$index] === '') {
                    return null;
                }
                // Split before decoding: an encoded slash stays within its single URL segment.
                $captures[$matches[1]] = rawurldecode($actual[$index]);
            } elseif ($segment !== $actual[$index]) {
                return null;
            }
        }

        return $captures;
    }

    /** @return array<string, string> */
    public function samples(string $pattern): array
    {
        $samples = [];
        foreach ($this->validate($pattern) as $name) {
            $samples[$name] = 'sample_'.$name;
        }

        return $samples;
    }

    public function replaceUrlPath(string $url, string $path): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The request URL must be absolute.');
        }

        return $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path.(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
