<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\EndpointIndex;
use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use App\Models\Revision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class MutationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_create_update_duplicate_and_delete_contract(): void
    {
        $curl = "curl 'https://upstream.test/characterized?x=1' -H 'Authorization: Bearer private-token'";
        Livewire::test(EndpointForm::class)->set('rawCurl', $curl)
            ->assertSee('Possible credentials detected')->call('save')->assertHasNoErrors();
        $endpoint = MockEndpoint::query()->sole();
        self::assertSame('GET /characterized', $endpoint->name);
        self::assertSame($curl, $endpoint->raw_curl);
        self::assertTrue($endpoint->exclude_auth);
        self::assertSame(0, Revision::query()->count());
        self::assertSame('Endpoint created – add a response so it can answer requests.', session('status'));
        Livewire::test(EndpointForm::class)->set('rawCurl', $curl)->call('save')
            ->assertHasErrors(['rawCurl' => 'This signature is already used by GET /characterized. Open that endpoint or change the matching request.']);
        Livewire::test(EndpointForm::class, ['endpoint' => $endpoint])->set('name', 'Renamed')->call('save')->assertHasNoErrors();
        self::assertSame('Endpoint changes saved.', session('status'));
        self::assertSame('GET /characterized', Revision::query()->sole()->snapshot['name']);
        $endpoint->responses()->create(['body' => 'delete with parent']);
        Livewire::test(EndpointIndex::class)->call('delete', $endpoint->id);
        $this->assertDatabaseMissing('mock_endpoints', ['id' => $endpoint->id]);
        $this->assertDatabaseCount('mock_responses', 0);
    }

    public function test_parsing_and_secret_masking_messages_are_stable(): void
    {
        Livewire::test(EndpointForm::class)->call('loadExample')->call('save')
            ->assertHasErrors(['rawCurl' => 'This is the sample curl. Replace its URL and values with the request you want to mock.']);
        $editor = Livewire::test(EndpointForm::class)->set('rawCurl', "curl 'https://example.test/secret?token=sensitive' -H 'Authorization: Bearer private'")
            ->assertSee('Possible credentials detected')->call('maskSecrets');
        self::assertStringNotContainsString('Bearer private', $editor->get('rawCurl'));
        self::assertStringContainsString('REPLACE_ME', $editor->get('rawCurl'));
        self::assertSame(0, MockEndpoint::query()->count());
    }

    public function test_response_headers_fixed_seed_and_template_errors_are_exact_and_do_not_write(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->set('headersJson', '[]')->call('save')->assertHasErrors(['headersJson' => 'Headers must be a JSON object of header names and values.']);
        $editor->set('headersJson', '{"X-Test":{"nested":true}}')->call('save')->assertHasErrors(['headersJson' => 'Header names and values must be single-line scalar values.']);
        $editor->set('headersJson', '{}')->set('seedMode', 'fixed')->set('seed', null)->call('save')
            ->assertHasErrors(['seed' => 'Enter an integer seed when Fixed is selected.']);
        $editor->set('seedMode', 'random')->set('callbackEnabled', true)->set('callbackUrl', '')->call('save')
            ->assertHasErrors(['callbackUrl' => 'Enter a callback URL before enabling callbacks.']);
        self::assertSame(0, $endpoint->responses()->count());
    }

    public function test_callback_update_keeps_secret_and_response_update_records_pre_state(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->set('body', 'initial')->call('save')->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Response added.');
        $response = $endpoint->responses()->sole();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->call('edit', $response->id);
        $editor->set('body', 'updated')->call('save')->assertHasNoErrors()->assertDispatched('toast', message: 'Response updated.');
        self::assertSame('initial', Revision::query()->sole()->snapshot['body']);
        $editor->call('edit', $response->id)->set('callbackSigningEnabled', true)->set('callbackSigningSecret', 'private-signing-secret')
            ->call('saveCallback')->assertHasNoErrors()->assertDispatched('toast', message: 'Callback saved.');
        $editor->set('callbackSigningSecret', '')->call('saveCallback')->assertHasNoErrors();
        self::assertSame('private-signing-secret', $response->fresh()->callback_signing_secret);
    }

    public function test_standard_validation_messages_match_the_pre_extraction_baseline(): void
    {
        foreach ([
            ['rawCurl', '', 'rawCurl', 'The raw curl field is required.'],
            ['priority', 1001, 'priority', 'The priority field must be between -1000 and 1000.'],
            ['name', str_repeat('x', 256), 'name', 'The name field must not be greater than 255 characters.'],
            ['environmentOverrides', [99999 => '1'], 'environmentOverrides', 'Every override must reference an existing environment.'],
            ['rawCurl', 'not a curl', 'rawCurl', 'The curl command contains more than one URL.'],
        ] as [$property, $value, $field, $message]) {
            $editor = Livewire::test(EndpointForm::class)->set('rawCurl', "curl 'https://probe.test/path'")
                ->set($property, $value)->call('save');
            self::assertSame([$message], $editor->instance()->getErrorBag()->get($field));
        }
        $endpoint = MockEndpoint::factory()->create();
        foreach ([
            ['statusCode', 600, 'statusCode', 'The status code field must be between 100 and 599.'],
            ['weight', 0, 'weight', 'The weight field must be at least 1.'],
            ['delayMs', -1, 'delayMs', 'The delay ms field must be at least 0.'],
            ['headersJson', 'bad', 'headersJson', 'Headers must be a valid JSON object: Syntax error'],
            ['callbackDelayMaxMs', -1, 'callbackDelayMaxMs', 'The callback delay max ms field must be between 0 and 30000.'],
            ['seedMode', 'fixed', 'seed', 'Enter an integer seed when Fixed is selected.'],
        ] as [$property, $value, $field, $message]) {
            $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->set($property, $value)->call('save');
            self::assertSame([$message], $editor->instance()->getErrorBag()->get($field));
        }
    }
}
