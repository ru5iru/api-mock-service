<?php

namespace Tests\Feature;

use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Services\Mutations\EndpointConfigurationValidator;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use App\Services\Mutations\EndpointRequestService;
use App\Services\Mutations\ResponseConfigurationValidator;
use App\Services\Mutations\ResponseInput;
use App\Services\Mutations\ResponseMutationService;
use App\Services\Mutations\ResponsePoolMutationService;
use App\Services\Mutations\SelectionInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SharedMutationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_inputs_support_validation_preview_and_persistence_without_livewire(): void
    {
        $input = new EndpointInput(rawCurl: "curl 'https://upstream.test/users/{id}?token=private&x=1' -H 'Authorization: Bearer secret'", pathPatternEnabled: true, excludedQueryParams: ['token']);
        $prepared = app(EndpointConfigurationValidator::class)->validate($input);
        self::assertSame('V6', $prepared['variant']->name);
        self::assertSame(0, MockEndpoint::query()->count());
        $analysis = app(EndpointRequestService::class)->analyzeHeaders($input, $prepared['parsed']);
        self::assertSame('••••••••', $analysis[0]['display_value']);
        self::assertTrue($analysis[0]['sensitive']);
        $service = app(EndpointMutationService::class);
        $endpoint = $service->save($input);
        self::assertSame($prepared['variant']->hash, $endpoint->curl_hash);
        self::assertSame($prepared['variant']->normalized, $endpoint->normalized_curl);
        $service->rename($endpoint->id, 'Changed');
        self::assertSame('GET /users/{id}', Revision::query()->sole()->snapshot['name']);
        $copy = $service->duplicate($endpoint->id);
        self::assertFalse($copy->enabled);
        self::assertNotSame($endpoint->uuid, $copy->uuid);
        $service->deleteMany([$copy->id]);
        $service->delete($endpoint->id);
        self::assertSame(0, MockEndpoint::query()->count());
    }

    public function test_response_validation_is_side_effect_free_and_scoped_update_cannot_touch_other_endpoint(): void
    {
        $a = MockEndpoint::factory()->create();
        $b = MockEndpoint::factory()->create();
        $service = app(ResponseMutationService::class);
        $input = new ResponseInput(body: 'original', headersJson: '{}');
        $attributes = app(ResponseConfigurationValidator::class)->validate($input);
        self::assertSame('original', $attributes['body']);
        self::assertSame(0, $a->responses()->count());
        $response = $service->save($a->id, $input);
        try {
            $service->save($b->id, new ResponseInput(body: 'wrong owner'), $response->id);
            self::fail('Expected scoped model lookup to fail.');
        } catch (ModelNotFoundException) {
            self::assertSame('original', $response->fresh()->body);
            self::assertSame(0, Revision::query()->count());
        }
        $service->save($a->id, new ResponseInput(body: 'changed'), $response->id);
        self::assertSame('original', Revision::query()->sole()->snapshot['body']);
    }

    public function test_pool_transaction_and_sequence_delete_reuse_existing_revision_rules(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $service = app(ResponseMutationService::class);
        $a = $service->save($endpoint->id, new ResponseInput(body: 'a'));
        $b = $service->save($endpoint->id, new ResponseInput(body: 'b'));
        app(ResponsePoolMutationService::class)->save($endpoint->id, new SelectionInput(selectionMode: 'sequence', responseOrder: [$b->id, $a->id]));
        self::assertSame(0, $b->fresh()->sequence_order);
        $service->delete($endpoint->id, $b->id);
        self::assertSame(0, $a->fresh()->sequence_order);
        $before = Revision::query()->count();
        try {
            app(ResponsePoolMutationService::class)->save($endpoint->id, new SelectionInput(selectionMode: 'rule', responseOrder: [$a->id]));
            self::fail('Rule pool without fallback must fail.');
        } catch (ValidationException) {
            self::assertSame('sequence', $endpoint->fresh()->selection_mode);
            self::assertSame($before, Revision::query()->count());
        }
    }
}
