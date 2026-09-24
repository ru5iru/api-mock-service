<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResponseTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_faker_catalog_is_mapped_cached_and_excludes_blocked_methods(): void
    {
        $response = $this->getJson('/api/faker-catalog')
            ->assertOk()
            ->assertHeader('ETag')
            ->assertJsonFragment(['id' => 'number.int'])
            ->assertJsonFragment(['id' => 'internet.username']);

        self::assertNotContains('helpers.multiple', array_column($response->json(), 'id'));
        $etag = $response->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->get('/api/faker-catalog')->assertStatus(304);
    }

    public function test_validate_reports_alias_warnings_unknown_methods_and_locations(): void
    {
        $this->postJson('/api/response-templates/validate', [
            'template' => "{\n  \"username\": \"\$internet.userName\",\n  \"bad\": \"\$person.fristName\"\n}",
            'locale' => 'en',
        ])->assertOk()
            ->assertJsonFragment(['severity' => 'warning', 'code' => 'RENAMED_METHOD'])
            ->assertJsonFragment(['severity' => 'error', 'code' => 'UNKNOWN_METHOD'])
            ->assertJsonPath('issues.0.line', 2);
    }

    public function test_preview_returns_typed_output_metrics_and_issues(): void
    {
        $this->postJson('/api/response-templates/preview', [
            'template' => '{"id":"$number.int({\"min\":9,\"max\":9})","name":"$person.fullName"}',
            'locale' => 'en',
            'seed_mode' => 'fixed',
            'seed' => 42,
        ])->assertOk()
            ->assertJsonPath('output.id', 9)
            ->assertJsonStructure(['output' => ['id', 'name'], 'bytes', 'render_ms', 'issues']);
    }

    public function test_preview_requires_a_value_for_fixed_seed_mode(): void
    {
        $this->postJson('/api/response-templates/preview', [
            'template' => '{"id":"$string.uuid"}',
            'seed_mode' => 'fixed',
            'seed' => null,
        ])->assertUnprocessable()
            ->assertJsonFragment(['code' => 'BAD_ARGS']);
    }
}
