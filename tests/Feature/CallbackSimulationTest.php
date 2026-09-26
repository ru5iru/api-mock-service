<?php

namespace Tests\Feature;

use App\Jobs\DeliverCallback;
use App\Livewire\Admin\ResponseManager;
use App\Models\CallbackAttempt;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Services\Callbacks\CallbackDelivery;
use App\Services\Config\ConfigExporter;
use App\Services\Environments\EnvironmentContext;
use App\Services\Revisions\RevisionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class CallbackSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_restoring_a_revision_from_before_callbacks_uses_callback_defaults(): void
    {
        $response = MockResponse::factory()->create();
        $revisions = app(RevisionManager::class);
        $legacySnapshot = collect($revisions->snapshot($response))
            ->reject(static fn ($value, string $key): bool => str_starts_with($key, 'callback_'))
            ->all();
        $revision = $revisions->record($response, $legacySnapshot);

        $response->update(['callback_enabled' => true, 'callback_url' => 'https://receiver.test/hook']);
        $revisions->restore($revision);

        self::assertFalse($response->fresh()->callback_enabled);
        self::assertSame('POST', $response->fresh()->callback_method);
        self::assertSame(1, $response->fresh()->callback_retry);
        self::assertSame('X-MockDeck-Signature', $response->fresh()->callback_signature_header);
    }

    public function test_restoring_a_revision_with_null_callback_fields_uses_database_defaults(): void
    {
        $response = MockResponse::factory()->create();
        $revisions = app(RevisionManager::class);
        $snapshot = $revisions->snapshot($response);
        $snapshot['callback_method'] = null;
        $snapshot['callback_retry'] = 0;
        $snapshot['callback_timeout_ms'] = 0;
        $snapshot['callback_signature_header'] = null;
        $revision = $revisions->record($response, $snapshot);

        $response->update(['status_code' => 201]);
        $revisions->restore($revision);

        self::assertSame('POST', $response->fresh()->callback_method);
        self::assertSame(1, $response->fresh()->callback_retry);
        self::assertSame(5000, $response->fresh()->callback_timeout_ms);
        self::assertSame('X-MockDeck-Signature', $response->fresh()->callback_signature_header);
    }

    public function test_primary_response_does_not_wait_for_the_callback_and_dispatches_after_response(): void
    {
        Queue::fake();
        Http::fake();
        $endpoint = MockEndpoint::factory()->create();
        MockResponse::factory()->for($endpoint, 'endpoint')->create([
            'callback_enabled' => true,
            'callback_url' => 'https://receiver.test/hook',
            'callback_body' => '{"ok":true}',
            'callback_delay_ms' => 30000,
        ]);

        $started = hrtime(true);
        $this->get('/users')->assertOk();
        self::assertLessThan(5000, (hrtime(true) - $started) / 1000000);
        Http::assertNothingSent();
        // Laravel's test kernel runs termination hooks before returning the response.
        Queue::assertPushed(DeliverCallback::class, 1);
    }

    public function test_callback_builder_reuses_schema_component_without_changing_response_template(): void
    {
        $response = MockResponse::factory()->create(['callback_body' => '{"event":"created"}']);

        Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('edit', $response->id)
            ->call('setCallbackEditorView', 'builder')
            ->set('callbackBuilderSchema.fields.0.value', 'updated')
            ->assertSet('callbackBody', fn (string $value): bool => str_contains($value, 'updated'))
            ->assertSet('bodyMode', 'static');

        self::assertSame('{"event":"created"}', $response->fresh()->callback_body);
    }

    public function test_callback_context_tokens_disable_builder_without_rewriting_json(): void
    {
        $response = MockResponse::factory()->create(['callback_body' => '{"method":"$request.method"}']);

        Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('edit', $response->id)
            ->assertSet('callbackBuilderSupported', false)
            ->call('setCallbackEditorView', 'builder')
            ->assertSet('callbackEditorView', 'json')
            ->assertSet('callbackBody', '{"method":"$request.method"}');
    }

    public function test_leaving_signing_secret_blank_in_editor_keeps_the_saved_secret(): void
    {
        $response = MockResponse::factory()->create([
            'callback_enabled' => true,
            'callback_url' => 'https://receiver.test/hook',
            'callback_body' => '{}',
            'callback_signing_enabled' => true,
            'callback_signing_secret' => 'keep-me',
        ]);

        Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('edit', $response->id)
            ->assertSet('callbackSigningSecret', '')
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame('keep-me', $response->fresh()->callback_signing_secret);
    }

    public function test_callback_context_retry_and_signature_reuse_the_shared_template_engine(): void
    {
        $environment = Environment::query()->sole();
        $environment->variables()->create(['key' => 'HOST', 'value' => 'receiver.test', 'is_secret' => false]);
        $environment->variables()->create(['key' => 'TOKEN', 'value' => 'hidden-token', 'is_secret' => true]);
        $response = MockResponse::factory()->create([
            'callback_enabled' => true,
            'callback_url' => 'https://{{env.HOST}}/hook/$request.json.id',
            'callback_headers' => ['X-Method' => 'Original $request.method', 'Authorization' => 'Bearer {{env.TOKEN}}'],
            'callback_body' => '{"method":"$request.method","id":"$request.json.id","token":"{{env.TOKEN}}"}',
            'callback_retry' => 4,
            'callback_backoff_ms' => 0,
            'callback_signing_enabled' => true,
            'callback_signing_secret' => 'first-secret',
        ]);
        Http::fakeSequence()->push('failure', 503)->push('failure', 503)->push('accepted', 200);

        app(CallbackDelivery::class)->deliver($response, 'request-42', $environment->id, [
            'method' => 'POST', 'json' => ['id' => 42], 'url' => 'http://mock.test/users',
        ]);

        Http::assertSentCount(3);
        $attempts = $response->callbackAttempts()->orderBy('id')->get();
        self::assertSame(['failed', 'failed', 'success'], $attempts->pluck('status')->all());
        self::assertSame([1, 2, 3], $attempts->pluck('attempt_number')->all());
        self::assertSame([$attempts[0]->id, $attempts[1]->id], $attempts->slice(1)->pluck('retry_of_id')->all());
        self::assertSame('https://receiver.test/hook/42', $attempts[0]->target_url);
        self::assertSame(['method' => 'POST', 'id' => 42, 'token' => 'hidden-token'], json_decode($attempts[0]->resolved_body, true));

        Http::assertSent(fn ($request): bool => $request->header('X-MockDeck-Signature')[0]
            === hash_hmac('sha256', $request->body(), 'first-secret')
            && $request->header('X-Method')[0] === 'Original POST');
        $this->getJson('/api/callback-attempts')->assertOk()->assertJsonCount(3, 'data');
        self::assertStringNotContainsString('hidden-token', $this->getJson('/api/callback-attempts')->getContent());
        self::assertStringNotContainsString('hidden-token', (string) DB::table('callback_attempts')->value('resolved_body'));
    }

    public function test_all_failed_attempts_stop_at_configured_limit(): void
    {
        $response = MockResponse::factory()->create([
            'callback_url' => 'https://receiver.test/hook',
            'callback_body' => '{"retry":true}',
            'callback_retry' => 2,
            'callback_backoff_ms' => 0,
        ]);
        Http::fake(['*' => Http::response('down', 503)]);

        app(CallbackDelivery::class)->deliver($response, 'request-failed', app(EnvironmentContext::class)->active()->id, []);

        Http::assertSentCount(2);
        self::assertSame(['failed', 'failed'], $response->callbackAttempts()->oldest('id')->pluck('status')->all());
    }

    public function test_redacted_callback_headers_and_query_disable_portable_copy(): void
    {
        MockResponse::factory()->create([
            'callback_enabled' => true,
            'callback_url' => 'https://receiver.test/hook?api_key=private-query',
            'callback_headers' => ['Authorization' => 'Bearer private-header'],
            'callback_body' => '{"ok":true}',
        ]);

        $export = app(ConfigExporter::class)->export()->toJson();
        self::assertStringNotContainsString('private-query', $export);
        self::assertStringNotContainsString('private-header', $export);
        $callback = json_decode($export, true)['endpoints'][0]['responses'][0]['callback'];
        self::assertFalse($callback['enabled']);
        self::assertTrue($callback['requires_secret_replacement']);
    }

    public function test_export_keeps_unconfigured_callback_url_null_for_no_op_imports(): void
    {
        MockResponse::factory()->create();

        $callback = app(ConfigExporter::class)->export()->data['endpoints'][0]['responses'][0]['callback'];

        self::assertNull($callback['url']);
        self::assertFalse($callback['enabled']);
        self::assertFalse($callback['requires_secret_replacement']);
    }

    public function test_resend_uses_original_resolved_bytes_and_a_fresh_signature(): void
    {
        $response = MockResponse::factory()->create([
            'callback_url' => 'https://receiver.test/hook',
            'callback_signing_enabled' => true,
            'callback_signing_secret' => 'first-secret',
        ]);
        Http::fake(['*' => Http::response('ok', 200)]);
        $delivery = app(CallbackDelivery::class);
        $environmentId = app(EnvironmentContext::class)->active()->id;
        $delivery->deliver($response, 'request-43', $environmentId, [], CallbackAttempt::query()->create([
            'response_id' => $response->id,
            'request_log_id' => 'request-43',
            'attempt_number' => 1,
            'target_url' => 'https://old-target.test/old',
            'method' => 'POST',
            'resolved_headers' => ['X-Old' => 'yes'],
            'resolved_body' => '{"old":true}',
            'status' => 'failed',
        ]));
        $original = CallbackAttempt::query()->oldest('id')->firstOrFail();
        $response->update(['callback_signing_secret' => 'second-secret', 'callback_url' => 'https://new-target.test/new']);
        $delivery->deliver($response->fresh(), 'request-43', $environmentId, [], $original);

        $latest = CallbackAttempt::query()->latest('id')->firstOrFail();
        self::assertSame($original->id, $latest->resend_of_id);
        self::assertSame($original->target_url, $latest->target_url);
        self::assertSame($original->resolved_body, $latest->resolved_body);
        self::assertSame('failed', $original->fresh()->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://old-target.test/old'
            && $request->header('X-MockDeck-Signature')[0] === hash_hmac('sha256', '{"old":true}', 'second-secret'));
    }

    public function test_resend_api_enqueues_an_immutable_copy_of_the_original_attempt(): void
    {
        Queue::fake();
        $response = MockResponse::factory()->create(['callback_url' => 'https://receiver.test/hook']);
        $original = CallbackAttempt::query()->create([
            'response_id' => $response->id,
            'request_log_id' => 'original-request',
            'attempt_number' => 1,
            'target_url' => 'https://original-target.test/hook',
            'method' => 'POST',
            'resolved_headers' => [],
            'resolved_body' => '{"immutable":true}',
            'status' => 'failed',
        ]);

        $this->postJson("/api/callback-attempts/{$original->id}/resend")
            ->assertStatus(202)
            ->assertJsonPath('data.resend_of_id', $original->id);
        app()->terminate();
        Queue::assertPushed(DeliverCallback::class, fn (DeliverCallback $job): bool => $job->resendAttemptId === $original->id);

        Http::fake(['*' => Http::response('ok', 200)]);
        app(CallbackDelivery::class)->deliver($response, 'original-request', app(EnvironmentContext::class)->active()->id, [], $original);
        self::assertSame('failed', $original->fresh()->status);
        self::assertSame('success', $response->callbackAttempts()->latest('id')->firstOrFail()->status);
    }

    public function test_disabled_signing_omits_the_header_and_secret_is_write_only_and_redacted_from_export(): void
    {
        $response = MockResponse::factory()->create([
            'callback_enabled' => true,
            'callback_url' => 'https://receiver.test/hook',
            'callback_body' => '{"ok":true}',
        ]);
        $this->patchJson("/api/responses/{$response->id}/callback", [
            'callback_signing_enabled' => true, 'callback_signing_secret' => 'secret-one',
        ])->assertOk()->assertJsonPath('data.callback_signing_secret', null);
        self::assertStringNotContainsString('secret-one', $this->getJson("/api/responses/{$response->id}/callback")->getContent());
        self::assertArrayNotHasKey('callback_signing_secret', $response->fresh()->toArray());
        self::assertStringNotContainsString('secret-one', app(ConfigExporter::class)->export(redactSecrets: false)->toJson());

        $before = app(RevisionManager::class)->snapshot($response->fresh());
        $this->patchJson("/api/responses/{$response->id}/callback", [
            'callback_signing_enabled' => false, 'callback_signing_secret' => 'secret-two',
            'callback_body' => '{"updated":true}',
        ])->assertOk();
        self::assertCount(2, $response->revisions()->get());
        self::assertNotSame($before['callback_signing_secret'], $response->fresh()->getRawOriginal('callback_signing_secret'));
        $diff = app(RevisionManager::class)->diffCurrent($response->revisions()->latest()->firstOrFail());
        self::assertStringNotContainsString('secret-one', json_encode($diff));
        self::assertSame('json-code', collect($diff['changes'])->firstWhere('path', 'callback_body')['renderer']);

        $headers = app(CallbackDelivery::class)->signHeaders($response->fresh(), [
            'X-MockDeck-Signature' => 'old', 'X-Other' => 'kept',
        ], '{"ok":true}');
        self::assertArrayNotHasKey('X-MockDeck-Signature', $headers);
        self::assertSame('kept', $headers['X-Other']);
    }

    public function test_test_callback_needs_no_previous_request_and_target_crash_is_isolated(): void
    {
        Queue::fake();
        $response = MockResponse::factory()->create([
            'callback_enabled' => true,
            'callback_url' => 'https://receiver.test/hook',
            'callback_body' => '{"test":true}',
        ]);
        $this->postJson("/api/responses/{$response->id}/callback/test")->assertStatus(202);
        Queue::assertPushed(DeliverCallback::class, 1);

        Http::fake(static function (): never {
            throw new \RuntimeException('Receiver failed.');
        });
        app(CallbackDelivery::class)->deliver($response, 'test-crash', app(EnvironmentContext::class)->active()->id, []);
        self::assertSame('failed', $response->callbackAttempts()->latest()->firstOrFail()->status);
    }
}
