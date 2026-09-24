<?php

namespace App\Services\Templates;

use App\Models\MockResponse;
use Illuminate\Support\Facades\Cache;

final readonly class ResponseTemplateEngine
{
    public function __construct(
        private TemplateCompiler $compiler,
        private TemplateRenderer $renderer,
    ) {}

    public function validate(string $template, string $locale = 'en'): TemplateValidationResult
    {
        return $this->compiler->compile($template, $locale);
    }

    public function preview(string $template, string $locale, string $seedMode, ?int $seed): array
    {
        $validation = $this->validate($template, $locale);
        if ($validation->compiled === null) {
            return [
                'output' => null,
                'bytes' => 0,
                'render_ms' => 0,
                'issues' => $validation->issueArrays(),
            ];
        }

        $rendered = $this->renderer->render($validation->compiled, $locale, $seedMode, $seed);

        return [
            'output' => $rendered->output,
            'bytes' => $rendered->bytes,
            'render_ms' => $rendered->renderMs,
            'issues' => $validation->issueArrays(),
        ];
    }

    public function renderResponse(MockResponse $response, ?string $requestHash = null): TemplateRenderResult
    {
        $cacheKey = sprintf(
            'mockdeck:response-template:%d:%s',
            $response->id,
            hash('sha256', ($response->updated_at?->format('U.u') ?? '0').'|'.(string) $response->template.'|'.$response->locale),
        );

        /** @var CompiledTemplate|null $compiled */
        $compiled = Cache::rememberForever($cacheKey, function () use ($response): ?CompiledTemplate {
            return $this->compiler->compile((string) $response->template, (string) $response->locale)->compiled;
        });

        if ($compiled === null) {
            throw new TemplateRenderException('The saved response template is invalid.', '/', '', 'TEMPLATE_RENDER_FAILED');
        }

        return $this->renderer->render(
            $compiled,
            (string) $response->locale,
            (string) $response->seed_mode,
            $response->seed === null ? null : (int) $response->seed,
            $requestHash,
        );
    }
}
