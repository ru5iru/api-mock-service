<?php

namespace App\Mcp;

use App\Services\Mcp\McpDispatcher;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class MockDeckTool extends Tool
{
    public function __construct(private readonly array $definition)
    {
        $this->name = $definition['name'];
        $this->description = $definition['description'];
    }

    public function toArray(): array
    {
        return $this->definition;
    }

    public function handle(Request $request): ResponseFactory
    {
        $result = app(McpDispatcher::class)->call($this->name, $request->all(), request()->attributes->get('mcp_credential'));

        return $result['ok'] ? Response::structured($result) : Response::make(Response::error($result['error']['message']))->withStructuredContent($result);
    }
}
