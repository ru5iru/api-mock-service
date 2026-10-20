<?php

namespace App\Services\Verification;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CallAssertionInput
{
    public function validate(array $values): array
    {
        $input = Validator::make($values, [
            'expect_count' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'comparator' => ['required', Rule::in(['equals', 'at_least', 'at_most'])],
            'matching' => ['sometimes', 'array', 'list', 'max:32'],
            'matching.*' => ['array:field_type,field_name,operator,value'],
            'matching.*.field_type' => ['required', Rule::in(['header', 'query', 'body_json_path'])],
            'matching.*.field_name' => ['required', 'string', 'max:255'],
            'matching.*.operator' => ['required', Rule::in(['equals', 'contains', 'regex', 'exists'])],
            'matching.*.value' => ['nullable', 'string', 'max:4096'],
        ])->validate();
        foreach ($input['matching'] ?? [] as $index => $condition) {
            if ($condition['operator'] !== 'exists' && ! is_string($condition['value'] ?? null)) {
                throw ValidationException::withMessages(["matching.$index.value" => 'This condition requires a string value.']);
            }
            if ($condition['operator'] === 'regex' && @preg_match($condition['value'], '') === false) {
                throw ValidationException::withMessages(["matching.$index.value" => 'Enter a valid regular expression including delimiters, for example /pattern/i.']);
            }
        }

        return $input;
    }
}
