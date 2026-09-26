<?php

namespace App\Services\Revisions;

final class StructuralJsonDiffer
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{path: string, type: string, before: mixed, after: mixed, renderer: string}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        $this->compare($before, $after, '', $changes);

        return $changes;
    }

    /** @param list<array{path: string, type: string, before: mixed, after: mixed, renderer: string}> $changes */
    private function compare(mixed $before, mixed $after, string $path, array &$changes): void
    {
        if ($before === $after) {
            return;
        }

        if (is_array($before) && is_array($after) && ! array_is_list($before) && ! array_is_list($after)) {
            $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
            sort($keys);

            foreach ($keys as $key) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;
                if (! array_key_exists($key, $before)) {
                    $changes[] = $this->change($childPath, 'added', null, $after[$key]);
                } elseif (! array_key_exists($key, $after)) {
                    $changes[] = $this->change($childPath, 'removed', $before[$key], null);
                } elseif ($before[$key] === $after[$key]) {
                    continue;
                } elseif ($this->compareAsOneValue($childPath, $before[$key], $after[$key])) {
                    $changes[] = $this->change($childPath, 'changed', $before[$key], $after[$key]);
                } else {
                    $this->compare($before[$key], $after[$key], $childPath, $changes);
                }
            }

            return;
        }

        $changes[] = $this->change($path, 'changed', $before, $after);
    }

    private function compareAsOneValue(string $path, mixed $before, mixed $after): bool
    {
        $root = explode('.', $path, 2)[0];

        return in_array($root, [
            'tags',
            'environment_overrides',
            'raw_curl',
            'normalized_curl',
            'body',
            'template',
            'callback_body',
        ], true) || (is_array($before) && array_is_list($before)) || (is_array($after) && array_is_list($after));
    }

    /** @return array{path: string, type: string, before: mixed, after: mixed, renderer: string} */
    private function change(string $path, string $type, mixed $before, mixed $after): array
    {
        $root = explode('.', $path, 2)[0];
        if ($root === 'callback_signing_secret') {
            $before = $before === null ? null : '[redacted]';
            $after = $after === null ? null : '[redacted]';
        }
        $renderer = match ($root) {
            'raw_curl', 'normalized_curl' => 'canonical-request',
            'body', 'template', 'callback_body' => 'json-code',
            'collection_id', 'tags', 'environment_overrides' => 'organization',
            default => 'plain',
        };

        return compact('path', 'type', 'before', 'after', 'renderer');
    }
}
