<?php

namespace App\Services\Templates;

use JsonException;
use stdClass;

final class TemplateSchemaConverter
{
    /** @return array<string, mixed> */
    public function emptySchema(): array
    {
        return [
            'root' => 'object',
            'count_mode' => 'fixed',
            'count' => 3,
            'min' => 2,
            'max' => 5,
            'fields' => [$this->emptyRow()],
        ];
    }

    /** @return array<string, mixed> */
    public function emptyRow(string $key = ''): array
    {
        return [
            'key' => $key,
            'type' => 'string',
            'mode' => 'fixed',
            'value' => '',
            'method' => 'person.firstName',
            'args' => '',
            'args_options' => [],
            'nullable' => 0,
            'format' => 'iso',
            'min' => 0,
            'max' => 100,
            'decimals' => 2,
            'length_mode' => 'fixed',
            'length' => 3,
            'length_min' => 2,
            'length_max' => 5,
            'children' => [],
            'item' => null,
        ];
    }

    /** @param array<string, mixed> $schema */
    public function schemaToTemplate(array $schema): string
    {
        $object = $this->rowsToObject($schema['fields'] ?? []);
        $root = $object;

        if (($schema['root'] ?? 'object') === 'list') {
            $repeat = ($schema['count_mode'] ?? 'fixed') === 'range'
                ? (object) ['min' => (int) ($schema['min'] ?? 1), 'max' => (int) ($schema['max'] ?? 1)]
                : (int) ($schema['count'] ?? 1);
            $root = (object) ['$repeat' => $repeat, '$item' => $object];
        }

        return json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    public function templateToSchema(string $template): ?array
    {
        try {
            $root = json_decode($template, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $schema = $this->emptySchema();
        if ($root instanceof stdClass && $this->isDirective($root, '$repeat')) {
            $values = get_object_vars($root);
            if (array_diff(array_keys($values), ['$repeat', '$item']) !== [] || ! ($values['$item'] ?? null) instanceof stdClass) {
                return null;
            }
            $schema['root'] = 'list';
            if (is_int($values['$repeat'])) {
                $schema['count_mode'] = 'fixed';
                $schema['count'] = $values['$repeat'];
            } elseif ($values['$repeat'] instanceof stdClass) {
                $range = get_object_vars($values['$repeat']);
                if (count($range) !== 2 || ! is_int($range['min'] ?? null) || ! is_int($range['max'] ?? null)) {
                    return null;
                }
                $schema['count_mode'] = 'range';
                $schema['min'] = $range['min'];
                $schema['max'] = $range['max'];
            } else {
                return null;
            }
            $root = $values['$item'];
        }

        if (! $root instanceof stdClass || $this->hasDirective($root)) {
            return null;
        }

        $rows = $this->objectToRows($root, 0);
        if ($rows === null) {
            return null;
        }
        $schema['fields'] = $rows === [] ? [$this->emptyRow()] : $rows;

        return $schema;
    }

    /** @param list<array<string, mixed>> $rows */
    private function rowsToObject(array $rows): stdClass
    {
        $object = new stdClass;
        foreach ($rows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $object->{$key} = $this->rowToValue($row);
        }

        return $object;
    }

    /** @param array<string, mixed> $row */
    private function rowToValue(array $row): mixed
    {
        $type = $row['type'] ?? 'string';
        $value = match ($type) {
            'faker' => $this->fakerValue($row),
            'string' => $this->stringValue($row),
            'number' => $this->numberValue($row),
            'boolean' => match ($row['mode'] ?? 'true') {
                'random' => '$datatype.boolean',
                'false' => false,
                default => true,
            },
            'date' => $this->dateValue($row),
            'object' => $this->rowsToObject($row['children'] ?? []),
            'array' => $this->arrayValue($row),
            default => (string) ($row['value'] ?? ''),
        };

        $nullable = max(0, min(100, (int) ($row['nullable'] ?? 0)));
        if ($nullable > 0) {
            return (object) ['$maybe' => 1 - ($nullable / 100), '$value' => $value, '$else' => null];
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function fakerValue(array $row): mixed
    {
        $method = (string) ($row['method'] ?? 'person.firstName');
        $argsText = trim((string) ($row['args'] ?? ''));
        if ($argsText === '') {
            $options = $this->normalizedArgumentOptions($row['args_options'] ?? []);
            if ($options !== []) {
                $args = match ($method) {
                    'helpers.arrayElement' => [$options['values'] ?? []],
                    'helpers.fromRegExp' => [(string) ($options['pattern'] ?? '')],
                    default => [(object) $options],
                };

                return (object) ['$faker' => $method, '$args' => $args];
            }

            return '$'.$method;
        }

        try {
            $args = json_decode($argsText, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $args = $argsText;
        }

        return (object) ['$faker' => $method, '$args' => $args];
    }

    /** @return array<string, mixed> */
    private function normalizedArgumentOptions(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $options = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || $value === '' || $value === null) {
                continue;
            }
            if (! is_string($value)) {
                $options[$key] = $value;
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^-?\d+$/D', $trimmed) === 1) {
                $options[$key] = (int) $trimmed;
                continue;
            }
            if (is_numeric($trimmed)) {
                $options[$key] = (float) $trimmed;
                continue;
            }
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                try {
                    $options[$key] = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                    continue;
                } catch (JsonException) {
                    // Keep invalid JSON as text so method validation can report it.
                }
            }
            $options[$key] = $value;
        }

        return $options;
    }

    /** @param array<string, mixed> $row */
    private function numberValue(array $row): int|float|string
    {
        return match ($row['mode'] ?? 'fixed') {
            'int' => '$number.int('.json_encode((object) ['min' => (int) ($row['min'] ?? 0), 'max' => (int) ($row['max'] ?? 100)], JSON_THROW_ON_ERROR).')',
            'float' => '$number.float('.json_encode((object) [
                'min' => (float) ($row['min'] ?? 0),
                'max' => (float) ($row['max'] ?? 100),
                'fractionDigits' => (int) ($row['decimals'] ?? 2),
            ], JSON_THROW_ON_ERROR).')',
            default => $this->fixedNumber($row['value'] ?? null),
        };
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row): string
    {
        $value = (string) ($row['value'] ?? '');
        if (($row['mode'] ?? 'fixed') === 'interpolated') {
            return $value;
        }

        // A leading $$ makes the whole string literal. Otherwise each
        // interpolation opener is escaped independently.
        return str_starts_with($value, '$')
            ? '$'.$value
            : str_replace('{{', '\\{{', $value);
    }

    /** @param array<string, mixed> $row */
    private function dateValue(array $row): mixed
    {
        $mode = (string) ($row['mode'] ?? 'recent');
        if ($mode === 'fixed') {
            return (string) ($row['value'] ?? '');
        }

        $args = match ($mode) {
            'between' => [(object) ['from' => (string) ($row['from'] ?? '-1 year'), 'to' => (string) ($row['to'] ?? 'now')]],
            'past', 'future' => [(object) ['years' => max(1, (int) ($row['years'] ?? 1))]],
            default => [(object) ['days' => max(1, (int) ($row['days'] ?? 1))]],
        };

        return (object) ['$faker' => 'date.'.$mode, '$args' => $args, '$format' => (string) ($row['format'] ?? 'iso')];
    }

    /** @param array<string, mixed> $row */
    private function arrayValue(array $row): stdClass
    {
        $repeat = ($row['length_mode'] ?? 'fixed') === 'range'
            ? (object) ['min' => (int) ($row['length_min'] ?? 1), 'max' => (int) ($row['length_max'] ?? 1)]
            : (int) ($row['length'] ?? 1);
        $item = is_array($row['item'] ?? null) ? $this->rowToValue($row['item']) : '';

        return (object) ['$repeat' => $repeat, '$item' => $item];
    }

    /** @return list<array<string, mixed>>|null */
    private function objectToRows(stdClass $object, int $depth): ?array
    {
        if ($depth > 6) {
            return null;
        }

        $rows = [];
        foreach (get_object_vars($object) as $key => $value) {
            $row = $this->valueToRow($value, $depth);
            if ($row === null) {
                return null;
            }
            $row['key'] = $key;
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function valueToRow(mixed $value, int $depth): ?array
    {
        $row = $this->emptyRow();

        if ($value instanceof stdClass && $this->isDirective($value, '$maybe')) {
            $values = get_object_vars($value);
            if (array_diff(array_keys($values), ['$maybe', '$value', '$else']) !== [] || ($values['$else'] ?? null) !== null || ! is_numeric($values['$maybe'])) {
                return null;
            }
            $row = $this->valueToRow($values['$value'], $depth);
            if ($row === null) {
                return null;
            }
            $row['nullable'] = (int) round((1 - (float) $values['$maybe']) * 100);

            return $row;
        }

        if ($value instanceof stdClass && $this->isDirective($value, '$faker')) {
            $values = get_object_vars($value);
            if (array_diff(array_keys($values), ['$faker', '$args', '$format']) !== []
                || ! is_string($values['$faker'])
                || (array_key_exists('$args', $values) && ! is_array($values['$args']))) {
                return null;
            }
            if (str_starts_with($values['$faker'], 'date.')) {
                return $this->dateDirectiveToRow($values, $row);
            }
            $row['type'] = 'faker';
            $row['method'] = $values['$faker'];
            $row['args'] = isset($values['$args']) ? json_encode($values['$args'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '';

            return $row;
        }

        if ($value instanceof stdClass && $this->isDirective($value, '$repeat')) {
            $values = get_object_vars($value);
            if (array_diff(array_keys($values), ['$repeat', '$item']) !== []) {
                return null;
            }
            $row['type'] = 'array';
            if (is_int($values['$repeat'])) {
                $row['length_mode'] = 'fixed';
                $row['length'] = $values['$repeat'];
            } elseif ($values['$repeat'] instanceof stdClass) {
                $range = get_object_vars($values['$repeat']);
                if (count($range) !== 2 || ! is_int($range['min'] ?? null) || ! is_int($range['max'] ?? null)) {
                    return null;
                }
                $row['length_mode'] = 'range';
                $row['length_min'] = $range['min'];
                $row['length_max'] = $range['max'];
            } else {
                return null;
            }
            $row['item'] = $this->valueToRow($values['$item'], $depth + 1);

            return $row['item'] === null ? null : $row;
        }

        if ($value instanceof stdClass) {
            if ($this->hasDirective($value)) {
                return null;
            }
            $children = $this->objectToRows($value, $depth + 1);
            if ($children === null) {
                return null;
            }
            $row['type'] = 'object';
            $row['children'] = $children;

            return $row;
        }

        if (is_array($value)) {
            return null;
        }

        if (is_string($value)) {
            if (str_starts_with($value, '$$')) {
                $row['type'] = 'string';
                $row['mode'] = 'fixed';
                $row['value'] = substr($value, 1);

                return $row;
            }
            if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*)(?:\((.*)\))?$/s', $value, $match) === 1) {
                $row['type'] = str_starts_with($match[1], 'number.') ? 'number' : ($match[1] === 'datatype.boolean' ? 'boolean' : 'faker');
                if ($row['type'] === 'faker') {
                    $row['method'] = $match[1];
                    $row['args'] = ($match[2] ?? '') === '' ? '' : '['.$match[2].']';
                } elseif ($row['type'] === 'number') {
                    $row['mode'] = $match[1] === 'number.float' ? 'float' : 'int';
                    $this->applyNumericArguments($row, $match[2] ?? '');
                } else {
                    $row['mode'] = 'random';
                }

                return $row;
            }
            $row['type'] = 'string';
            $unescaped = str_replace('\\{{', "\0ESCAPED_OPEN\0", $value);
            if (str_contains($unescaped, '{{') && preg_match('/^\{\{[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*(?:\(.*\))?\}\}$/s', $unescaped) !== 1) {
                return null;
            }
            $row['mode'] = str_contains($unescaped, '{{') ? 'interpolated' : 'fixed';
            $row['value'] = $row['mode'] === 'fixed' ? str_replace('\\{{', '{{', $value) : $value;

            return $row;
        }

        if (is_bool($value)) {
            $row['type'] = 'boolean';
            $row['mode'] = $value ? 'true' : 'false';

            return $row;
        }

        if (is_int($value) || is_float($value)) {
            $row['type'] = 'number';
            $row['mode'] = 'fixed';
            $row['value'] = $value;

            return $row;
        }

        return null;
    }

    /** @param array<string, mixed> $values @param array<string, mixed> $row @return array<string, mixed>|null */
    private function dateDirectiveToRow(array $values, array $row): ?array
    {
        $mode = substr($values['$faker'], 5);
        if (! in_array($mode, ['recent', 'past', 'future', 'between'], true)) {
            return null;
        }
        $args = isset($values['$args']) && is_array($values['$args']) ? $values['$args'] : [];
        $options = isset($args[0]) && $args[0] instanceof stdClass ? get_object_vars($args[0]) : [];
        $row['type'] = 'date';
        $row['mode'] = $mode;
        $row['format'] = $values['$format'] ?? 'iso';
        foreach (['days', 'years', 'from', 'to'] as $key) {
            if (array_key_exists($key, $options)) {
                $row[$key] = $options[$key];
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function applyNumericArguments(array &$row, string $text): void
    {
        try {
            $args = json_decode('['.$text.']', true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }
        $options = isset($args[0]) && is_array($args[0]) ? $args[0] : [];
        foreach (['min', 'max'] as $key) {
            if (isset($options[$key])) {
                $row[$key] = $options[$key];
            }
        }
        if (isset($options['fractionDigits'])) {
            $row['decimals'] = $options['fractionDigits'];
        }
    }

    private function fixedNumber(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $text = (string) $value;

        return str_contains($text, '.') ? (float) $value : (int) $value;
    }

    private function isDirective(stdClass $object, string $directive): bool
    {
        return array_key_exists($directive, get_object_vars($object));
    }

    private function hasDirective(stdClass $object): bool
    {
        foreach (array_keys(get_object_vars($object)) as $key) {
            if (str_starts_with($key, '$')) {
                return true;
            }
        }

        return false;
    }
}
