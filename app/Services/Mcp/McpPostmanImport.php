<?php

namespace App\Services\Mcp;

use App\Models\TestRun;
use App\Services\Config\ImportMode;
use App\Services\Environments\EnvironmentContext;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use InvalidArgumentException;

/** Inert orchestration only: Postman syntax, auth, variables and scripts belong to the existing mapper. */
final class McpPostmanImport
{
    public function document(TestRun $run, array $args): array
    {
        try {
            return $this->map($run, $args);
        } catch (InvalidArgumentException $exception) {
            throw new McpDomainException(McpErrorCode::ValidationError, 'The Postman import is invalid.', ['errors' => [$exception->getMessage()]]);
        }
    }

    private function map(TestRun $run, array $args): array
    {
        $mapper = app(PostmanMapper::class);
        $values = isset($args['environment']) ? $mapper->environmentValues($args['environment']) : [];

        return $mapper->withVariableOptions([
            'environment_file_values' => $values, 'mode' => $args['variable_mode'] ?? 'exclude',
            'environment_values' => app(EnvironmentContext::class)->variables($run->environment),
        ])->map($args['collection'], $args['mask_secrets'] ?? false);
    }

    public function preview(TestRun $run, array $args): array
    {
        return app(MappedImportService::class)->previewDocument($this->document($run, $args), ImportMode::from($args['mode'] ?? 'create-only'), $run)->toArray();
    }

    /** Dispatcher already holds the run lock; retry cache and writes share that transaction. */
    public function apply(TestRun $run, array $args): array
    {
        return app(McpIdempotency::class)->execute($run, 'apply_postman_import', $args, function () use ($run, $args): array {
            if ($run->status !== 'active') {
                throw new McpDomainException(McpErrorCode::RunFinished, 'This run is finished.');
            }
            try {
                $result = app(MappedImportService::class)->applyDocument($this->document($run, $args), ImportMode::from($args['mode'] ?? 'create-only'), $run);
            } catch (InvalidArgumentException $exception) {
                throw new McpDomainException(McpErrorCode::ValidationError, 'The Postman import cannot be applied.', ['errors' => [$exception->getMessage()]]);
            }
            $items = array_map(static fn (array $item): array => [
                'mock_id' => $item['endpoint']->uuid, 'name' => $item['endpoint']->displayName(), 'action' => $item['action'],
                'response_count' => $item['endpoint']->responses()->count(),
                'revision_reference' => $item['endpoint']->revisions()->latest('version_number')->value('id'),
                'invocation' => app(McpMockUrls::class)->forMock($run, $item['endpoint']),
            ], $result['endpoints']);

            return app(McpOutput::class)->safe(['run_id' => $run->public_id, 'summary' => $result['summary']->toArray(),
                'items' => $items, 'plan' => $result['plan']->toArray()], $run);
        });
    }
}
