<?php

namespace App\Services\Mcp;

use App\Models\TestRun;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use App\Services\Templates\TemplateIssue;
use App\Services\Templates\TemplateRenderException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Pure preview adapter: no saved-response rendering cache, audit or mutation APIs. */
final class McpTemplatePreview
{
    public static function requested(Request $request): bool
    {
        return $request->isMethod('POST') && $request->input('method') === 'tools/call'
            && $request->input('params.name') === 'preview_template';
    }

    public function execute(TestRun $run, array $args): array
    {
        $endpoint = $response = null;
        if (isset($args['response_id'])) {
            $endpoint = app(McpReads::class)->mock($run, $args['mock_id']);
            $response = $endpoint->responses()->where('uuid', $args['response_id'])->first();
            if ($response === null) {
                throw new McpDomainException(McpErrorCode::NotFound, 'Response does not belong to this mock.');
            }
            if ($response->body_mode !== 'template') {
                throw ValidationException::withMessages(['response_id' => 'Choose a response in Template mode.']);
            }
        }
        $template = $args['template'] ?? $response->template;
        $locale = $args['locale'] ?? $response?->locale ?? 'en';
        $seed = $args['seed'] ?? 'random';
        try {
            $result = app(ResponseTemplateEngine::class)->preview($template, $locale, is_int($seed) ? 'fixed' : 'random',
                is_int($seed) ? $seed : null, $endpoint, $run->environment);
        } catch (TemplateRenderException $exception) {
            $result = ['output' => null, 'bytes' => 0, 'render_ms' => 0,
                'issues' => [(new TemplateIssue('error', $exception->issueCode, $exception->templatePath, 1, 1, $exception->getMessage()))->toArray()]];
        }
        $result = ['synthetic_context' => false, ...$result, 'sample_notice' => TemplateContext::PREVIEW_NOTICE];
        if (collect($result['issues'])->contains(fn (array $issue) => $issue['severity'] === 'error')) {
            throw new McpDomainException(McpErrorCode::ValidationError, 'The template preview contains validation errors.', $result);
        }

        return $result;
    }
}
