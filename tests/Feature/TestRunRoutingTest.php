<?php

namespace Tests\Feature;

use App\Jobs\DeliverCallback;
use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\TestRun;
use App\Services\Callbacks\CallbackDelivery;
use App\Services\Config\ConfigExporter;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Environments\EnvironmentContext;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use App\Services\TestRuns\RunRouting;
use App\Services\TestRuns\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TestRunRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function createRun(?Environment $environment = null): TestRun
    {
        $owner = app(McpCredentialService::class)->generate('run owner', ['write', 'cleanup'])->credential;

        return app(TestRunService::class)->create($owner, $environment ?? app(EnvironmentContext::class)->active(), 'HTTP test');
    }

    private function endpoint(?TestRun $run, string $body, string $curl = 'curl http://localhost/shared?x=a%2Fb&y=c'): MockEndpoint
    {
        $signature = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($curl), false, false, false);
        $endpoint = MockEndpoint::factory()->create([
            'test_run_id' => $run?->id, 'raw_curl' => $curl, 'method' => 'GET',
            'normalized_curl' => $signature->normalized, 'curl_hash' => $signature->hash,
            'exclude_auth' => false, 'exclude_headers' => false, 'exclude_cookies' => false,
        ]);
        $endpoint->responses()->create(['body' => $body]);

        return $endpoint;
    }

    public function test_header_and_prefix_are_equivalent_and_never_fall_through(): void
    {
        $a = $this->createRun();
        $b = $this->createRun();
        $ordinary = $this->endpoint(null, 'ordinary');
        $first = $this->endpoint($a, 'first');
        $second = $this->endpoint($b, 'second');
        $empty = $this->createRun();
        $target = '/shared?x=a%2Fb&y=c';
        $this->get($target)->assertOk()->assertContent('ordinary');
        foreach ([$a->public_id => 'first', $b->public_id => 'second'] as $publicId => $body) {
            $this->get($target, [RunRouting::HEADER => $publicId])->assertOk()->assertContent($body);
            $this->get(RunRouting::PREFIX.$publicId.$target)->assertOk()->assertContent($body);
        }
        $this->get($target, [RunRouting::HEADER => $empty->public_id])->assertNotFound()->assertJsonPath('error', 'No mock configured for this request');
        $this->get(RunRouting::PREFIX.$empty->public_id.$target)->assertNotFound()->assertJsonPath('error', 'No mock configured for this request');
        foreach ([$ordinary->id => 1, $first->id => 2, $second->id => 2] as $id => $count) {
            self::assertSame($count, EndpointCallState::query()->where('endpoint_id', $id)->sole()->total_match_count);
        }
        self::assertSame($a->id, EndpointCallState::query()->where('endpoint_id', $first->id)->sole()->test_run_id);
        $this->get('/shared?x=different', [RunRouting::HEADER => $a->public_id])->assertNotFound();
    }

    public function test_ordinary_requests_cannot_match_run_only_and_invalid_scopes_fail_closed(): void
    {
        $run = $this->createRun();
        $this->endpoint($run, 'hidden');
        $this->get('/shared?x=a%2Fb&y=c')->assertNotFound();
        foreach (['', 'invalid', (string) $run->id, (string) Str::uuid()] as $id) {
            $this->get('/shared?x=a%2Fb&y=c', [RunRouting::HEADER => $id])->assertNotFound();
        }
        $this->get(RunRouting::PREFIX.$run->public_id.'/shared?x=a%2Fb&y=c', [RunRouting::HEADER => (string) Str::uuid()])->assertStatus(400);
        DB::table('test_runs')->where('id', $run->id)->update(['status' => 'finished', 'finished_at' => now()]);
        $this->get('/shared?x=a%2Fb&y=c', [RunRouting::HEADER => $run->public_id])->assertNotFound();
        $this->get(RunRouting::PREFIX.$run->public_id.'/shared?x=a%2Fb&y=c')->assertNotFound();
    }

    public static function requestOrigins(): array
    {
        return [['http://localhost'], ['https://localhost:18473']];
    }

    #[DataProvider('requestOrigins')]
    public function test_environment_binding_and_request_context_do_not_mutate_global_environment(string $origin): void
    {
        $active = app(EnvironmentContext::class)->active();
        $active->variables()->create(['key' => 'KEY', 'value' => 'global', 'is_secret' => false]);
        $bound = Environment::query()->create(['name' => 'Run environment']);
        $bound->variables()->create(['key' => 'KEY', 'value' => 'run', 'is_secret' => false]);
        $run = $this->createRun($bound);
        $endpoint = $this->endpoint($run, 'unused');
        $endpoint->responses()->first()->update(['body_mode' => 'template', 'template' => '{"env":"{{env.KEY}}","url":"{{$request.url}}"}']);
        $response = $this->get($origin.RunRouting::PREFIX.$run->public_id.'/shared?x=a%2Fb&y=c');
        $response->assertOk()->assertJsonPath('env', 'run')->assertJsonPath('url', $origin.'/shared?x=a%2Fb&y=c');
        self::assertSame($active->id, app(EnvironmentContext::class)->active()->id);
        self::assertSame((string) $active->id, DB::table('app_settings')->where('key', 'active_environment_id')->value('value'));
        self::assertSame($bound->id, EndpointCallState::query()->sole()->environment_id);
    }

    public function test_portable_export_and_dashboard_exclude_runtime_configuration(): void
    {
        $this->freezeTime();
        $ordinary = $this->endpoint(null, 'ordinary');
        $before = app(ConfigExporter::class)->export()->data;
        $run = $this->createRun();
        $endpoint = $this->endpoint($run, 'secret run payload');
        $this->get('/shared?x=a%2Fb&y=c', [RunRouting::HEADER => $run->public_id])->assertOk();
        self::assertSame(json_encode($before), json_encode(app(ConfigExporter::class)->export()->data));
        $this->get('/dashboard/endpoints/'.$endpoint->id.'/edit')->assertNotFound();
        self::assertSame([$ordinary->id], MockEndpoint::query()->pluck('id')->all());
    }

    public function test_reserved_routes_cannot_be_served_as_operator_mocks(): void
    {
        foreach (['/mcp', '/mcp/tools', '/_mockdeck', '/_mockdeck/unknown'] as $path) {
            $this->endpoint(null, 'must not serve', 'curl http://localhost'.$path);
            $response = $this->get($path);
            $response->assertStatus($path === '/mcp' ? 401 : 404)->assertDontSee('must not serve');
        }
        $this->expectException(ValidationException::class);
        app(EndpointMutationService::class)->save(new EndpointInput(rawCurl: 'curl http://localhost/mcp'));
    }

    public function test_pattern_matching_body_and_bound_environment_overrides_use_the_same_scope(): void
    {
        $active = app(EnvironmentContext::class)->active();
        $bound = Environment::query()->create(['name' => 'Pattern run']);
        $run = $this->createRun($bound);
        $curl = 'curl http://localhost/users/{id}?q=a%2Fb -H "Content-Type: application/json" --data \'{"input":"exact"}\'';
        $parsed = app(CurlParser::class)->parse($curl);
        $variant = app(CurlHasher::class)->forOptions($parsed, false, false, false, [], [], true);
        $endpoint = MockEndpoint::factory()->create([
            'test_run_id' => $run->id, 'method' => 'POST', 'raw_curl' => $curl,
            'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
            'path_pattern_enabled' => true, 'signature_version' => 6, 'exclude_headers' => false,
        ]);
        $endpoint->environmentOverrides()->attach($active->id, ['enabled' => false]);
        $endpoint->responses()->create(['body_mode' => 'template', 'template' => '{"id":"{{$request.path.id}}","input":"{{$request.json.input}}"}']);
        foreach ([['/users/42?q=a%2Fb', ['HTTP_X_MOCKDECK_TEST_RUN' => $run->public_id]],
            [RunRouting::PREFIX.$run->public_id.'/users/42?q=a%2Fb', []]] as [$url, $server]) {
            $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', ...$server], '{"input":"exact"}')
                ->assertOk()->assertExactJson(['id' => '42', 'input' => 'exact']);
        }
        $this->call('POST', RunRouting::PREFIX.$run->public_id.'/users/42?q=a%2Fb', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"input":"different"}')->assertNotFound();
        $endpoint->environmentOverrides()->attach($bound->id, ['enabled' => false]);
        $this->call('POST', RunRouting::PREFIX.$run->public_id.'/users/42?q=a%2Fb', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"input":"exact"}')->assertNotFound();
    }

    public function test_root_paths_and_identical_dual_metadata_are_supported(): void
    {
        $run = $this->createRun();
        $this->endpoint($run, 'root', 'curl http://localhost/');
        $this->get(RunRouting::PREFIX.$run->public_id, [RunRouting::HEADER => $run->public_id])->assertOk()->assertContent('root');
        $this->get('/')->assertNotFound();
    }

    #[DataProvider('requestOrigins')]
    public function test_callback_job_uses_run_environment_and_stripped_request_context(string $origin): void
    {
        Queue::fake();
        Http::fake(['receiver.test/*' => Http::response('ok')]);
        $bound = Environment::query()->create(['name' => 'Callback run']);
        $bound->variables()->create(['key' => 'KEY', 'value' => 'bound value', 'is_secret' => false]);
        $run = $this->createRun($bound);
        $endpoint = $this->endpoint($run, 'ok');
        $endpoint->responses()->first()->update([
            'callback_enabled' => true, 'callback_url' => 'https://receiver.test/hook',
            'callback_body' => '{"value":"{{env.KEY}}","url":"{{$request.url}}"}',
        ]);
        $this->get($origin.RunRouting::PREFIX.$run->public_id.'/shared?x=a%2Fb&y=c')->assertOk();
        Queue::assertPushed(DeliverCallback::class, function ($job) use ($bound): bool {
            self::assertSame($bound->id, $job->environmentId);
            $job->handle(app(CallbackDelivery::class));

            return true;
        });
        Http::assertSent(fn ($request) => json_decode($request->body(), true) === [
            'value' => 'bound value', 'url' => $origin.'/shared?x=a%2Fb&y=c',
        ]);
    }

    public function test_revoked_owner_makes_an_active_run_unroutable(): void
    {
        $run = $this->createRun();
        $this->endpoint($run, 'private');
        app(McpCredentialService::class)->revoke($run->credential_id);
        $this->get('/shared?x=a%2Fb&y=c', [RunRouting::HEADER => $run->public_id])->assertNotFound();
    }
}
