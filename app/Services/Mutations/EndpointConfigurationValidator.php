<?php

namespace App\Services\Mutations;

use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\TestRun;
use App\Services\Curl\HashVariant;
use App\Services\Curl\ParsedCurl;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class EndpointConfigurationValidator
{
    /** @return array{parsed: ParsedCurl, variant: HashVariant, matching: array} */
    public function validate(EndpointInput $input, ?int $endpointId = null, ?TestRun $run = null, bool $checkDuplicates = true): array
    {
        Validator::make($input->values(), [
            'name' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'priority' => ['required', 'integer', 'between:-1000,1000'],
            'rawCurl' => ['required', 'string', 'max:1048576'],
            'excludeCookies' => ['boolean'],
            'excludeAuth' => ['boolean'],
            'excludeHeaders' => ['boolean'],
            'pathPatternEnabled' => ['boolean'],
            'excludedQueryParams' => ['array', 'max:100'],
            'excludedHeaderNames' => ['array', 'max:100'],
            'collectionId' => ['nullable', 'integer', 'exists:collections,id'],
            'tagIds' => ['array'],
            'tagIds.*' => ['integer', 'distinct', 'exists:tags,id'],
            'environmentOverrides' => ['array'],
            'environmentOverrides.*' => ['nullable', 'in:,0,1'],
        ])->validate();

        $overrideIds = array_values(array_unique(array_map('intval', array_keys($input->environmentOverrides))));
        if (Environment::query()->whereKey($overrideIds)->count() !== count($overrideIds)) {
            throw ValidationException::withMessages(['environmentOverrides' => 'Every override must reference an existing environment.']);
        }

        if (trim($input->rawCurl) === trim(EndpointRequestService::EXAMPLE_CURL)) {
            throw ValidationException::withMessages(['rawCurl' => 'This is the sample curl. Replace its URL and values with the request you want to mock.']);
        }

        try {
            ['parsed' => $parsed, 'variant' => $variant, 'matching' => $matching] = app(EndpointRequestService::class)->prepare($input);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $target = str_starts_with($field, 'excluded_query_params') ? 'excludedQueryParams'
                    : (str_starts_with($field, 'excluded_headers') ? 'excludedHeaderNames' : 'pathPatternEnabled');
                $errors[$target] = $messages[0];
            }

            throw ValidationException::withMessages($errors);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rawCurl' => $exception->getMessage()]);
        }

        $duplicate = MockEndpoint::query()->withoutGlobalScope('ordinary')->where('test_run_id', $run?->id)
            ->where('curl_hash', $variant->hash)
            ->when($endpointId !== null, fn ($query) => $query->where('id', '!=', $endpointId))
            ->orderBy('id')
            ->first();

        if ($checkDuplicates && $duplicate !== null) {
            throw ValidationException::withMessages(['rawCurl' => 'This signature is already used by '.$duplicate->displayName().'. Open that endpoint or change the matching request.']);
        }

        return compact('parsed', 'variant', 'matching');
    }

    public function metadataReady(EndpointInput $input): bool
    {
        return Validator::make([
            'name' => $input->name, 'priority' => $input->priority,
            'collectionId' => $input->collectionId, 'tagIds' => $input->tagIds,
            'environmentOverrides' => $input->environmentOverrides,
        ], [
            'name' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'between:-1000,1000'],
            'collectionId' => ['nullable', 'integer', 'exists:collections,id'],
            'tagIds' => ['array'], 'tagIds.*' => ['integer', 'distinct', 'exists:tags,id'],
            'environmentOverrides.*' => ['nullable', 'in:,0,1'],
        ])->passes() && Environment::query()->whereKey(array_keys($input->environmentOverrides))->count() === count($input->environmentOverrides);
    }
}
