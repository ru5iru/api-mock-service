<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class UiShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shell_exposes_accessible_navigation_and_clean_footer(): void
    {
        $response = $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Skip to main content')
            ->assertSee('Documentation')
            ->assertSee('data-unmatched-badge hidden', false)
            ->assertSee('MockDeck v'.config('mock.portable_config.generator_version'))
            ->assertDontSee('HASH-FIRST MATCHING')
            ->assertDontSee('NO REQUEST-LOG TABLE');

        $html = $response->getContent();
        self::assertLessThan(strpos($html, 'css/tokens.css'), strpos($html, 'js/theme.js'));
    }

    public function test_documentation_page_explains_core_terms_and_shortcuts(): void
    {
        $this->get('/dashboard/docs')
            ->assertOk()
            ->assertSee('Documentation')
            ->assertSee('Signature version')
            ->assertSee('Match type')
            ->assertSee('New endpoint');
    }

    public function test_page_header_renders_one_compact_heading_with_actions(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-page-header title="Endpoints" :count="3">
                <x-slot:actions><button type="button">New endpoint</button></x-slot:actions>
            </x-page-header>
        BLADE);

        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString('page-header', $html);
        self::assertStringContainsString('page-header-actions', $html);
        self::assertStringContainsString('Endpoints', $html);
    }

    public function test_sign_in_shell_resolves_theme_before_styles(): void
    {
        config()->set('mock.dashboard_auth.enabled', true);

        $html = $this->get('/dashboard/login')->assertOk()->getContent();

        self::assertLessThan(strpos($html, 'css/tokens.css'), strpos($html, 'js/theme.js'));
        self::assertStringContainsString('meta name="color-scheme"', $html);
        self::assertSame(1, substr_count($html, '<h1'));
    }
}
