<?php

namespace App\Services\Mcp;

use App\Models\EndpointCallState;
use App\Models\McpCredential;
use App\Models\McpUnmatchedRequest;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\TestRun;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Mutations\EndpointConfigurationValidator;
use App\Services\Mutations\ResponsePoolMutationService;
use App\Services\Response\FaultConfigurationValidator;
use App\Services\Response\ResponseSelectionService;

final class McpReads
{
    public function run(McpCredential $owner, string $id, bool $lock = false): TestRun
    {
        $run = TestRun::query()->where('credential_id', $owner->id)->where('public_id', $id)->when($lock, fn ($query) => $query->lockForUpdate())->with('environment')->first();
        if ($run === null) {
            throw new McpDomainException(McpErrorCode::RunNotFound, 'No owned test run has that public ID.');
        }

        return $run;
    }

    public function mock(TestRun $run, string $id): MockEndpoint
    {
        $endpoint = $run->endpoints()->where('uuid', $id)->first();
        if ($endpoint === null) {
            throw new McpDomainException(McpErrorCode::NotFound, 'No mock in this run has that ID.');
        }

        return $endpoint;
    }

    public function execute(string $tool, array $args, McpCredential $owner, ?TestRun $run): array
    {
        if ($tool === 'preview_postman_import') {
            return app(McpPostmanImport::class)->preview($run, $args);
        }
        if ($tool === 'preview_template') {
            return app(McpTemplatePreview::class)->execute($run, $args);
        }
        if ($tool === 'list_test_runs') {
            return ['runs' => TestRun::query()->where('credential_id', $owner->id)->when(isset($args['status']), fn ($q) => $q->where('status', $args['status']))->orderByDesc('id')->paginate($args['per_page'] ?? 20, ['public_id', 'label', 'status', 'created_at'], 'page', $args['page'] ?? 1)->toArray()];
        }
        if ($tool === 'validate_mock') {
            $existing = isset($args['mock_id']) ? $this->mock($run, $args['mock_id']) : null;
            $config = isset($args['changes']) ? app(McpMockConfiguration::class)->changes($args['changes'], $existing) : $args['configuration'];
            $input = app(McpMockConfiguration::class)->endpoint(collect($config)->except('responses')->all(), $existing);
            app(EndpointConfigurationValidator::class)->validate($input, $existing?->id, $run, $run !== null);
            app(ResponsePoolMutationService::class)->validate(app(McpMockConfiguration::class)->pool($config, $existing));

            return ['valid' => true, 'errors' => [], 'warnings' => app(McpMockConfiguration::class)->warnings($input)];
        }
        if ($tool === 'list_mocks') {
            $query = $run->endpoints()->when(isset($args['name']), fn ($q) => $q->where('name', 'like', '%'.$args['name'].'%'))
                ->when(isset($args['method']), fn ($q) => $q->where('method', strtoupper($args['method'])))
                ->when(isset($args['path']), fn ($q) => $q->where('normalized_curl', 'like', '%'.$args['path'].'%'));
            $page = $query->orderBy('id')->paginate($args['per_page'] ?? 20, ['id', 'uuid', 'name', 'method', 'enabled', 'normalized_curl'], 'page', $args['page'] ?? 1);

            return ['items' => $page->getCollection()->map(fn ($endpoint) => ['mock_id' => $endpoint->uuid, 'name' => $endpoint->displayName(), 'method' => $endpoint->method, 'path' => $endpoint->requestPath(), 'enabled' => $endpoint->enabled])->all(),
                'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
        }
        if ($tool === 'get_unmatched_requests') {
            $query = McpUnmatchedRequest::query()->where('run_id', $run->id)->where('created_at', '>=', now()->subDay())->orderByDesc('sequence');
            $page = $query->paginate($args['per_page'] ?? 20, ['*'], 'page', $args['page'] ?? 1);

            return ['items' => $page->getCollection()->map(fn ($row) => ['id' => $row->id, 'created_at' => $row->created_at->toIso8601String(), 'complete' => $row->complete,
                'request' => collect($row->request)->except('headers', 'body')->all()])->all(), 'page' => $page->currentPage(), 'total_retained' => $page->total(),
                'window' => ['capacity' => 20, 'ttl_hours' => 24, 'body_bytes' => 65536, 'complete_history' => false, 'note' => 'A bounded recent unmatched window, not proof of all requests. IDs expire; explain_match can use retained encrypted evidence.']];
        }
        if ($tool === 'explain_match') {
            if (isset($args['log_entry_id'])) {
                $row = McpUnmatchedRequest::query()->where('run_id', $run->id)->where('created_at', '>=', now()->subDay())->find($args['log_entry_id']);
                if ($row === null) {
                    throw new McpDomainException(McpErrorCode::NotFound, 'The unmatched entry expired or is outside this run.');
                }
                if (! $row->complete) {
                    throw new McpDomainException(McpErrorCode::InconclusiveEvidence, 'Captured request was truncated; provide the full curl for an exact explanation.');
                }
                $data = $row->request;
                $parsed = new ParsedCurl($data['method'], $data['url'], $data['headers'], $data['body']);
            } else {
                $parsed = app(CurlParser::class)->parse($args['curl']);
            }

            return app(McpExplainMatch::class)->explain($run, $parsed);
        }
        $endpoint = $this->mock($run, $args['mock_id']);

        return match ($tool) {
            'get_mock' => $this->configuration($endpoint),
            'get_mock_url' => app(McpMockUrls::class)->forMock($run, $endpoint, $args['preferred'] ?? 'header'),
            'peek_next_response' => $this->peek($run, $endpoint),
            'get_calls' => $this->calls($run, $endpoint, $args['limit'] ?? 20),
        };
    }

    public function version(MockEndpoint $endpoint): int
    {
        return (int) $endpoint->revisions()->max('version_number');
    }

    public function configuration(MockEndpoint $endpoint): array
    {
        return ['mock_id' => $endpoint->uuid, 'name' => $endpoint->displayName(), 'enabled' => $endpoint->enabled, 'priority' => $endpoint->priority, 'selection_mode' => $endpoint->selection_mode, 'sequence_on_exhaust' => $endpoint->sequence_on_exhaust,
            'curl' => $endpoint->raw_curl, 'matching' => $endpoint->only(['exclude_cookies', 'exclude_auth', 'exclude_headers', 'excluded_query_params', 'excluded_headers', 'path_pattern_enabled']),
            'collection_id' => $endpoint->collection_id, 'tags' => $endpoint->tags->modelKeys(), 'version' => $this->version($endpoint),
            'revision_reference' => $endpoint->revisions()->latest('version_number')->value('id'),
            'responses' => $endpoint->responses()->with('rules')->limit(50)->get()->map(fn ($response) => $this->responseConfiguration($response))->all(),
            'history_url' => url('/dashboard/test-runs/'.$endpoint->testRun->public_id.'/history'),
            'limits' => ['max_responses' => 50, 'text_output_bytes' => 4096, 'redacted' => true]];
    }

    private function responseConfiguration(MockResponse $response): array
    {
        return ['id' => $response->uuid, 'status' => $response->status_code, 'headers' => $response->headers,
            'body' => $response->body_mode === 'template' ? $response->template : $response->body, 'body_mode' => $response->body_mode, 'weight' => $response->weight,
            'delay_ms' => $response->delay_ms, 'locale' => $response->locale, 'seed' => $response->seed, 'seed_mode' => $response->seed_mode,
            'sequence_order' => $response->sequence_order, 'is_default' => $response->is_default,
            ...app(FaultConfigurationValidator::class)->attributes($response->getAttributes()),
            'rule_conditions' => $response->rules->map(fn ($rule) => $rule->only(['field_type', 'field_name', 'operator', 'value', 'priority']))->all()];
    }

    private function peek(TestRun $run, MockEndpoint $endpoint): array
    {
        $count = $endpoint->responses()->count();
        if ($endpoint->selection_mode !== 'sequence') {
            return ['applicable' => false, 'selection_mode' => $endpoint->selection_mode, 'sequence_position' => null,
                'response_count' => $count, 'next_position' => null, 'position_label' => null, 'reason' => 'not_applicable', 'response' => null];
        }
        // The serving engine's preview reads existing run/environment state only.
        // Never call select(): it creates state, captures evidence and consumes a step.
        $result = app(ResponseSelectionService::class)->preview($endpoint, $run->environment);
        $next = $result->response === null ? null : $result->response->sequence_order + 1;

        return ['applicable' => true, 'selection_mode' => 'sequence',
            'sequence_position' => $result->sequencePosition ?? ($this->state($run, $endpoint)?->sequence_position ?? 0),
            'response_count' => $count, 'next_position' => $next, 'position_label' => $next === null ? null : "$next of $count", 'reason' => $result->reason,
            'response' => $result->response === null ? null : $this->responseConfiguration($result->response)];
    }

    public function state(TestRun $run, MockEndpoint $endpoint): ?EndpointCallState
    {
        return EndpointCallState::query()->where('test_run_id', $run->id)->where('endpoint_id', $endpoint->id)->where('environment_id', $run->environment_id)->first();
    }

    public function calls(TestRun $run, MockEndpoint $endpoint, int $limit): array
    {
        $state = $this->state($run, $endpoint);
        $recent = $state?->recent_call_digests ?? [];

        return ['total_match_count' => $state?->total_match_count ?? 0, 'last_matched_at' => $state?->last_matched_at?->toIso8601String(),
            'sequence_position' => $endpoint->selection_mode === 'sequence' ? ($state?->sequence_position ?? 0) : null, 'recent_calls' => array_slice($recent, -$limit),
            'window' => ['capacity' => 20, 'retained_calls' => count($recent), 'returned_calls' => min($limit, count($recent)),
                'unretained_calls' => max(0, ($state?->total_match_count ?? 0) - count($recent)), 'exact_total' => true,
                'complete_evidence' => ($state?->total_match_count ?? 0) <= min($limit, count($recent))]];
    }
}
