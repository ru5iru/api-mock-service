<?php

namespace App\Livewire\Admin\Concerns;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlParser;
use App\Services\Curl\MockCurlBuilder;
use App\Services\Curl\MockCurlEnvironmentResolver;
use App\Services\Templates\TemplateRenderException;
use InvalidArgumentException;

/** Shared explicit-copy action; values are resolved on the server at click time. */
trait CopiesMockCurl
{
    public function copyMockCurl(int $endpointId): ?string
    {
        $endpoint = MockEndpoint::query()->findOrFail($endpointId);
        try {
            $request = app(CurlParser::class)->parse($endpoint->raw_curl);

            return app(MockCurlBuilder::class)->build(app(MockCurlEnvironmentResolver::class)->resolve($request));
        } catch (TemplateRenderException|InvalidArgumentException $exception) {
            $this->dispatch('toast', message: 'Cannot copy mock curl: '.$exception->getMessage().' Check variables in the active Environment.', tone: 'danger');

            return null;
        }
    }
}
