<?php

namespace Tests\Feature;

use Tests\TestCase;

final class UiShellTest extends TestCase
{
    public function test_dashboard_shell_exposes_accessible_navigation_and_clean_footer(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Skip to main content')
            ->assertSee('Documentation')
            ->assertSee('MockDeck v'.config('mock.portable_config.generator_version'))
            ->assertDontSee('HASH-FIRST MATCHING')
            ->assertDontSee('NO REQUEST-LOG TABLE');
    }

    public function test_documentation_page_explains_core_terms_and_shortcuts(): void
    {
        $this->get('/dashboard/docs')
            ->assertOk()
            ->assertSee('MockDeck documentation')
            ->assertSee('Signature version')
            ->assertSee('Match type')
            ->assertSee('New endpoint');
    }
}
