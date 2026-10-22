<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Environments\EnvironmentContext;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use App\Services\Templates\TemplateRenderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class TemplatePreviewContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_preview_environment_does_not_change_dashboard_defaults_or_active_settings(): void
    {
        $active = app(EnvironmentContext::class)->active();
        $active->variables()->create(['key' => 'KEY', 'value' => 'dashboard', 'is_secret' => false]);
        $bound = Environment::create(['name' => 'Preview bound', 'is_default' => false]);
        $bound->variables()->create(['key' => 'KEY', 'value' => 'bound', 'is_secret' => false]);
        $settings = json_encode(DB::table('app_settings')->get()->toArray());
        $engine = app(ResponseTemplateEngine::class);
        $template = '{"env":"{{env.KEY}}","name":"$person.fullName"}';
        $normal = $engine->preview($template, 'en', 'fixed', 42);
        $scoped = $engine->preview($template, 'en', 'fixed', 42, environment: $bound);
        self::assertSame('dashboard', $normal['output']->env);
        self::assertSame('bound', $scoped['output']->env);
        self::assertSame($normal['output']->name, $scoped['output']->name);
        self::assertTrue($scoped['synthetic_context']);
        self::assertSame($settings, json_encode(DB::table('app_settings')->get()->toArray()));
        self::assertSame($active->id, app(EnvironmentContext::class)->active()->id);
        Livewire::test(ResponseManager::class, ['endpoint' => MockEndpoint::factory()->create()])->call('setBodyMode', 'template')->assertSee(TemplateContext::PREVIEW_NOTICE);
    }

    public function test_secret_preview_guard_uses_the_explicit_environment_without_falling_back(): void
    {
        $active = app(EnvironmentContext::class)->active();
        $active->variables()->create(['key' => 'KEY', 'value' => 'public', 'is_secret' => false]);
        $bound = Environment::create(['name' => 'Secret bound', 'is_default' => false]);
        $bound->variables()->create(['key' => 'KEY', 'value' => 'private', 'is_secret' => true]);
        $this->expectException(TemplateRenderException::class);
        $this->expectExceptionMessage('Preview hidden: the template references a secret environment variable.');
        app(ResponseTemplateEngine::class)->preview('{"value":"{{env.KEY}}"}', 'en', 'fixed', 42, environment: $bound);
    }
}
