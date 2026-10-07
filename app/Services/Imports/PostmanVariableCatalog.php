<?php

namespace App\Services\Imports;

use App\Services\Environments\EnvironmentVariableWriter;
use InvalidArgumentException;

/** Inert, scoped variable inventory shared with future import passes. */
final class PostmanVariableCatalog
{
    private array $candidates = [];

    public function declarations(mixed $entries, string $source): array
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new InvalidArgumentException($source.' variables must be an array.');
        }
        $values = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['disabled'] ?? false) || ($entry['enabled'] ?? true) === false) {
                continue;
            }
            $key = $entry['key'] ?? $entry['id'] ?? null;
            if (! is_string($key) || ! EnvironmentVariableWriter::validKey($key)) {
                throw new InvalidArgumentException($source.' has an invalid variable name.');
            }
            $value = $entry['value'] ?? null;
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Variable '.$key.' must have a scalar value.');
            }
            if (strlen((string) $value) > 65535) {
                throw new InvalidArgumentException('Variable '.$key.' exceeds the 65535-byte limit.');
            }
            $values[$key] = $value === null ? null : (is_bool($value) ? json_encode($value) : (string) $value);
        }

        return $values;
    }

    public function tokens(string $text): array
    {
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $text, $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_map(static fn (array $match): string => $match[0], $matches)));
    }

    public function observe(array $scope, string $source, string $text = ''): void
    {
        $names = array_keys($scope);
        foreach ($this->tokens($text) as $token) {
            $name = trim(substr($token, 2, -2));
            if (! str_starts_with($name, '$') && EnvironmentVariableWriter::validKey($name)) {
                $names[] = $name;
            }
        }
        foreach (array_unique($names) as $name) {
            $known = array_key_exists($name, $scope) && $scope[$name] !== null;
            $candidate = $this->candidates[$name] ?? ['name' => $name, 'value' => null, 'known' => false, 'is_secret' => EnvironmentVariableWriter::sensitiveName($name), 'sources' => [], 'scope_conflict' => false];
            if ($known && $candidate['known'] && $candidate['value'] !== $scope[$name]) {
                $candidate['scope_conflict'] = true;
            }
            if ($known) {
                $candidate['value'] = $scope[$name];
                $candidate['known'] = true;
            }
            $candidate['sources'] = array_values(array_unique([...$candidate['sources'], $source]));
            $this->candidates[$name] = $candidate;
            if (count($this->candidates) > 500) {
                throw new InvalidArgumentException('The import exceeds the 500-variable review limit.');
            }
        }
    }

    public function candidates(array $environmentValues, array &$warnings): array
    {
        foreach ($environmentValues as $name => $value) {
            if (isset($this->candidates[$name]) && $this->candidates[$name]['known'] && $this->candidates[$name]['value'] !== $value) {
                $warnings[] = 'Environment file overrides embedded variable '.$name.'.';
            }
            $this->candidates[$name] = [...($this->candidates[$name] ?? ['name' => $name, 'sources' => [], 'is_secret' => EnvironmentVariableWriter::sensitiveName($name)]), 'value' => $value, 'known' => $value !== null, 'scope_conflict' => false];
            $this->candidates[$name]['sources'][] = 'Environment file';
        }
        foreach ($this->candidates as &$candidate) {
            if ($candidate['scope_conflict']) {
                $candidate['known'] = false;
                $candidate['value'] = null;
                $warnings[] = 'Variable '.$candidate['name'].' has different folder-scoped values; choose its value in Environment management before resolving.';
            }
        }
        unset($candidate);
        ksort($this->candidates);

        return array_values($this->candidates);
    }
}
