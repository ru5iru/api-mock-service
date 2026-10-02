<?php

namespace App\Services\Response;

use Illuminate\Validation\ValidationException;

final class SelectionConfigurationValidator
{
    public function validate(array $endpoint, array $responses): void
    {
        $errors = [];
        $mode = $endpoint['selection_mode'] ?? 'weighted';
        if (! in_array($mode, ['weighted', 'sequence', 'rule'], true)) {
            $errors['selection_mode'] = 'Choose Weighted, Sequence, or Rule-based selection.';
        }
        if ($mode === 'sequence') {
            if (! in_array($endpoint['sequence_on_exhaust'] ?? 'repeat_last', ['repeat_last', 'loop', 'not_found'], true)) {
                $errors['sequence_on_exhaust'] = 'Choose a supported On exhaust behavior.';
            }
            $orders = array_column($responses, 'sequence_order');
            sort($orders);
            if (count($responses) === 0 || $orders !== range(0, count($responses) - 1)) {
                $errors['sequence_order'] = 'Sequence responses must have unique, consecutive positions starting at zero.';
            }
        }
        if ($mode === 'rule' && count(array_filter($responses, fn (array $response) => (bool) ($response['is_default'] ?? false))) !== 1) {
            $errors['is_default'] = 'Rule-based selection requires exactly one Default / fallback response.';
        }
        foreach ($responses as $responseIndex => $response) {
            $rules = $response['response_rules'] ?? $response['rules'] ?? [];
            if (! is_array($rules)) {
                $errors["responseRules.$responseIndex"] = 'Conditions must be a list.';

                continue;
            }
            foreach ($rules as $ruleIndex => $rule) {
                $key = "responseRules.$responseIndex.$ruleIndex";
                if (! is_array($rule)) {
                    $errors[$key] = 'Each condition must be an object.';

                    continue;
                }
                $name = $rule['field_name'] ?? null;
                if (! in_array($rule['field_type'] ?? '', ['header', 'query', 'body_json_path'], true) || ! is_string($name) || trim($name) === '' || strlen($name) > 255) {
                    $errors[$key] = 'Each condition needs a supported field type and a field name.';
                }
                $operator = $rule['operator'] ?? '';
                if (! in_array($operator, ['equals', 'contains', 'regex', 'exists'], true)) {
                    $errors[$key] = 'Choose Equals, Contains, Regex, or Exists.';
                }
                if ($operator !== 'exists' && ! is_string($rule['value'] ?? null)) {
                    $errors[$key] = 'This condition requires a value.';
                }
                if ($operator === 'regex' && (! is_string($rule['value'] ?? null) || @preg_match($rule['value'], '') === false)) {
                    $errors[$key] = 'Enter a valid regular expression including delimiters, for example /pattern/i.';
                }
                if (! is_int($rule['priority'] ?? null) || $rule['priority'] < 0 || $rule['priority'] > 2147483647) {
                    $errors[$key] = 'Condition priority must be a non-negative integer.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
