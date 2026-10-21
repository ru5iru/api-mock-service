<?php

namespace App\Services\Mcp;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\McpCredential;
use App\Models\MockEndpoint;
use App\Models\TestRun;
use App\Services\Environments\EnvironmentContext;
use App\Services\Mutations\EndpointConfigurationValidator;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use App\Services\Mutations\ResponseMutationService;
use App\Services\Revisions\RevisionManager;
use App\Services\TestRuns\RunRouting;
use App\Services\TestRuns\TestRunService;
use App\Services\Verification\CallAssertionInput;
use App\Services\Verification\CallAssertionService;
use App\Services\Verification\RuntimeStateReset;
use Illuminate\Validation\ValidationException;

/** Called inside the dispatch transaction holding the owning run's lock. */
final class McpWrites
{
    public function execute(string $tool, array $args, McpCredential $owner, ?TestRun $run): array
    {
        if ($tool === 'start_test_run') {
            $environment = isset($args['environment']) ? Environment::query()->where('name', $args['environment'])->first() : app(EnvironmentContext::class)->active();
            if ($environment === null) {
                throw ValidationException::withMessages(['environment' => 'Choose an existing Environment name.']);
            }
            $run = app(TestRunService::class)->create($owner, $environment, $args['label']);
            $origin = rtrim(config('app.url'), '/');

            return ['run_id' => $run->public_id, 'owner_id' => $owner->id, 'label' => $run->label, 'status' => $run->status,
                'environment' => ['id' => $environment->id, 'name' => $environment->name], 'preferred' => $args['preferred'] ?? 'header',
                'routing' => ['header' => ['base_url' => $origin, 'name' => RunRouting::HEADER, 'value' => $run->public_id],
                    'path_prefix' => ['base_url' => $origin.RunRouting::PREFIX.$run->public_id], 'instructions' => 'Append the original request path and query. Use either the header on the ordinary URL or the prefixed base URL; no fallback to ordinary mocks.']];
        }
        if ($tool === 'assert_calls') {
            $endpoint = app(McpReads::class)->mock($run, $args['mock_id']);
            $input = app(CallAssertionInput::class)->validate($args);
            $result = app(CallAssertionService::class)->evaluate(app(McpReads::class)->state($run, $endpoint), $input['matching'] ?? [], $input['comparator'], $input['expect_count']);
            $result['message'] = 'Run '.$run->public_id.', mock '.$endpoint->uuid.': '.$result['message'];
            if (! $result['passed']) {
                throw new McpDomainException($result['verdict'] === 'indeterminate' ? McpErrorCode::InconclusiveEvidence : McpErrorCode::AssertionFailed, $result['message'], $result);
            }

            return $result;
        }
        if ($tool === 'finish_test_run' && $run->final_summary !== null) {
            return $run->final_summary;
        }
        if ($tool !== 'create_mock' && $tool !== 'finish_test_run' && $run->status !== 'active') {
            throw new McpDomainException(McpErrorCode::RunFinished, 'This run is finished. Read its evidence or start another run.');
        }
        if ($tool === 'create_mock') {
            return app(McpIdempotency::class)->execute($run, $tool, $args, function () use ($run, $args): array {
                if ($run->status !== 'active') {
                    throw new McpDomainException(McpErrorCode::RunFinished, 'This run is finished.');
                }
                $config = $args['configuration'];
                if (empty($config['responses'])) {
                    throw ValidationException::withMessages(['responses' => 'Provide at least one response.']);
                }
                $input = app(McpMockConfiguration::class)->endpoint(collect($config)->except('responses')->all());
                $this->noCollision($run, $input);
                $endpoint = app(EndpointMutationService::class)->save($input, run: $run);
                foreach ($config['responses'] as $response) {
                    app(ResponseMutationService::class)->save($endpoint->id, app(McpMockConfiguration::class)->response($response), run: $run);
                }
                $this->revision($run, $endpoint, $this->snapshot($endpoint), 'MCP create_mock: initial configuration');

                return app(McpOutput::class)->safe($this->result($run, $endpoint), $run);
            });
        }
        if ($tool === 'update_mock') {
            $endpoint = app(McpReads::class)->mock($run, $args['mock_id']);
            $endpoint = $run->endpoints()->lockForUpdate()->findOrFail($endpoint->id);
            $version = app(McpReads::class)->version($endpoint);
            if ($version !== $args['expected_version']) {
                throw new McpDomainException(McpErrorCode::Conflict, 'Configuration changed; read get_mock and retry with its current version.', ['current_version' => $version]);
            }
            $before = $this->snapshot($endpoint);
            $input = app(McpMockConfiguration::class)->endpoint($args['changes']['endpoint'] ?? [], $endpoint);
            $this->noCollision($run, $input, $endpoint);
            $endpoint = app(EndpointMutationService::class)->save($input, $endpoint->id, $run);
            $changes = $args['changes']['responses'] ?? [];
            $ids = [...array_column($changes['update'] ?? [], 'id'), ...($changes['remove'] ?? [])];
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['responses' => 'Each response can be changed only once per operation.']);
            }
            foreach ($changes['update'] ?? [] as $data) {
                $response = $endpoint->responses()->where('uuid', $data['id'])->first();
                if ($response === null) {
                    throw new McpDomainException(McpErrorCode::NotFound, 'Response does not belong to this mock.');
                }
                app(ResponseMutationService::class)->save($endpoint->id, app(McpMockConfiguration::class)->response($data, $response), $response->id, $run);
            }
            foreach ($changes['remove'] ?? [] as $id) {
                $response = $endpoint->responses()->where('uuid', $id)->first();
                if ($response === null) {
                    throw new McpDomainException(McpErrorCode::NotFound, 'Response does not belong to this mock.');
                }
                app(ResponseMutationService::class)->delete($endpoint->id, $response->id, $run);
            }
            foreach ($changes['add'] ?? [] as $data) {
                app(ResponseMutationService::class)->save($endpoint->id, app(McpMockConfiguration::class)->response($data), run: $run);
            }
            if ($endpoint->responses()->count() > 50) {
                throw ValidationException::withMessages(['responses' => 'P0 supports at most 50 responses per mock.']);
            }
            if ($this->snapshot($endpoint) !== $before) {
                $this->revision($run, $endpoint, $before, 'MCP update_mock');
            }

            return $this->result($run, $endpoint);
        }
        if ($tool === 'reset_test_run') {
            foreach ($run->endpoints()->orderBy('id')->get() as $endpoint) {
                $this->revision($run, $endpoint, $this->snapshot($endpoint), 'MCP reset_test_run: configuration preserved; runtime state cleared');
            }
            $count = app(RuntimeStateReset::class)->reset(EndpointCallState::query()->where('test_run_id', $run->id));

            return ['run_id' => $run->public_id, 'reset' => true, 'state_rows_reset' => $count, 'configuration_preserved' => true];
        }
        $delete = $run->status === 'active' && $args['cleanup'] === 'delete_mocks';
        $summary = ['run_id' => $run->public_id, 'status' => 'finished', 'cleanup' => $run->status === 'finished' ? 'keep_mocks' : $args['cleanup'], 'mock_count' => $run->endpoints()->count(),
            'total_match_count' => (int) EndpointCallState::query()->where('test_run_id', $run->id)->sum('total_match_count'), 'finished_at' => now()->toIso8601String()];
        if ($delete) {
            foreach ($run->endpoints()->orderBy('id')->get() as $endpoint) {
                $this->revision($run, $endpoint, $this->snapshot($endpoint), 'MCP finish_test_run: delete_mocks');
            }
        }
        app(TestRunService::class)->finish($owner, $run->public_id, $delete);
        $run->refresh();
        $run->final_summary = $summary;
        $run->save();

        return $summary;
    }

    private function noCollision(TestRun $run, EndpointInput $input, ?MockEndpoint $current = null): void
    {
        $prepared = app(EndpointConfigurationValidator::class)->validate($input, $current?->id, $run, false);
        $existing = $run->endpoints()->where('curl_hash', $prepared['variant']->hash)->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))->first();
        if ($existing !== null) {
            throw new McpDomainException(McpErrorCode::Conflict, 'An identical canonical signature already belongs to '.$existing->displayName().'. Use update_mock.', ['mock_id' => $existing->uuid, 'name' => $existing->displayName()]);
        }
    }

    private function snapshot(MockEndpoint $endpoint): array
    {
        $endpoint->refresh()->load(['tags', 'environmentOverrides']);
        $manager = app(RevisionManager::class);

        return [...$manager->snapshot($endpoint), 'import_response_pool' => $endpoint->responses()->with('rules')->get()->map(fn ($response) => $manager->snapshot($response))->all()];
    }

    private function revision(TestRun $run, MockEndpoint $endpoint, array $before, string $note): void
    {
        app(RevisionManager::class)->record($endpoint, $before, note: $note, run: $run);
    }

    private function result(TestRun $run, MockEndpoint $endpoint): array
    {
        return [...app(McpReads::class)->configuration($endpoint), 'invocation' => app(McpMockUrls::class)->forMock($run, $endpoint),
            'matcher_summary' => ['signature_version' => $endpoint->signatureVariant(), 'path' => $endpoint->requestPath(), 'selection_mode' => $endpoint->selection_mode, 'scope' => $run->public_id],
            'warnings' => app(McpMockConfiguration::class)->warnings(app(McpMockConfiguration::class)->endpoint([], $endpoint))];
    }
}
