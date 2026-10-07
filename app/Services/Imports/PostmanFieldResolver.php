<?php

namespace App\Services\Imports;

use InvalidArgumentException;

/** Resolves matching fields only; request bodies remain captured literal text. */
final readonly class PostmanFieldResolver
{
    public function __construct(private array $options) {}

    public function resolve(string $scope, string $type, string $name, string $value, array $tokens): array
    {
        $id = hash('sha256', $scope."\0".$type."\0".$name);
        $mode = $this->options['field_overrides'][$id] ?? $this->options['mode'] ?? 'exclude';
        if ($mode === 'default') {
            $mode = $this->options['mode'] ?? 'exclude';
        }
        if (! in_array($mode, ['exclude', 'resolve'], true)) {
            throw new InvalidArgumentException('Choose Exclude or Resolve for variable fields.');
        }
        $missing = [];
        if ($mode === 'resolve') {
            $value = preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/', function (array $match) use (&$missing): string {
                $key = $match[1];
                if (! array_key_exists($key, $this->options['environment_values'] ?? [])) {
                    $missing[] = $key;

                    return $match[0];
                }

                return (string) $this->options['environment_values'][$key];
            }, $value);
        }
        if ($type === 'header' && strpbrk($value, "\r\n\0") !== false) {
            $missing[] = 'unsafe header value';
        }

        return ['value' => $value, 'field' => ['id' => $id, 'field_type' => $type, 'field_name' => $name, 'tokens' => $tokens, 'mode' => $mode, 'error' => $missing === [] ? null : 'Cannot resolve '.$name.': '.implode(', ', array_unique($missing)).'. Create the variables or choose Exclude.']];
    }
}
