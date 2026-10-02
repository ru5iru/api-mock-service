<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Response\ResponseSelectionService;
use App\Services\Response\SelectionConfigurationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ResponseSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_order_and_repeat_last_are_stateful_and_preview_has_no_side_effects(): void
    {
        [$endpoint, $environment, $responses] = $this->sequence();
        $service = app(ResponseSelectionService::class);
        self::assertSame($responses[0]->id, $service->preview($endpoint, $environment)->response->id);
        self::assertSame(0, EndpointCallState::query()->count());
        foreach ([0, 1, 2, 2, 2] as $index) {
            self::assertSame($responses[$index]->id, $service->select($endpoint, Request::create('/'), $environment)->response->id);
        }
        $state = EndpointCallState::query()->sole();
        self::assertSame(5, $state->sequence_position);
        self::assertSame(5, $state->total_match_count);
        self::assertNotNull($state->last_matched_at);
    }

    public function test_loop_and_not_found_exhaustion(): void
    {
        [$endpoint, $environment, $responses] = $this->sequence('loop');
        $service = app(ResponseSelectionService::class);
        foreach ([0, 1, 2, 0, 1, 2] as $index) {
            self::assertSame($responses[$index]->id, $service->select($endpoint, Request::create('/'), $environment)->response->id);
        }
        $endpoint->update(['sequence_on_exhaust' => 'not_found']);
        $result = $service->select($endpoint, Request::create('/'), $environment);
        self::assertNull($result->response);
        self::assertSame('sequence_exhausted', $result->reason);
        self::assertSame(7, EndpointCallState::query()->sole()->total_match_count);
    }

    public function test_reset_only_resets_active_environment_sequence_and_preserves_counts(): void
    {
        [$endpoint, $environment, $responses] = $this->sequence();
        $other = Environment::query()->create(['name' => 'Other']);
        $service = app(ResponseSelectionService::class);
        $service->select($endpoint, Request::create('/'), $environment);
        $service->select($endpoint, Request::create('/'), $other);
        $service->resetSequence($endpoint, $environment);
        self::assertSame($responses[0]->id, $service->preview($endpoint, $environment)->response->id);
        self::assertSame($responses[1]->id, $service->preview($endpoint, $other)->response->id);
        self::assertSame([1, 1], EndpointCallState::query()->orderBy('id')->pluck('total_match_count')->all());
    }

    public function test_weighted_mode_preserves_single_response_and_tracks_every_match(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $environment = Environment::query()->firstOrFail();
        $response = $endpoint->responses()->create(['body' => 'static', 'weight' => 5]);
        foreach (range(1, 4) as $call) {
            self::assertSame($response->id, app(ResponseSelectionService::class)->select($endpoint, Request::create('/'), $environment)->response->id);
        }
        self::assertSame('weighted', $endpoint->fresh()->selection_mode);
        self::assertSame(4, EndpointCallState::query()->sole()->total_match_count);
        self::assertSame(0, EndpointCallState::query()->sole()->sequence_position);
    }

    public function test_rule_priority_and_all_conditions_must_match_before_falling_back(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'rule']);
        $environment = Environment::query()->firstOrFail();
        $fallback = $endpoint->responses()->create(['is_default' => true]);
        // A default with conditions stays a fallback, even with the highest priority.
        $fallback->rules()->create(['field_type' => 'query', 'field_name' => 'x', 'operator' => 'exists', 'priority' => 0]);
        $lower = $endpoint->responses()->create([]);
        $higher = $endpoint->responses()->create([]);
        $lower->rules()->create(['field_type' => 'query', 'field_name' => 'x', 'operator' => 'exists', 'priority' => 20]);
        $higher->rules()->create(['field_type' => 'header', 'field_name' => 'X-Tenant', 'operator' => 'equals', 'value' => 'blue', 'priority' => 1]);
        $higher->rules()->create(['field_type' => 'body_json_path', 'field_name' => 'user.role', 'operator' => 'equals', 'value' => 'admin', 'priority' => 2]);
        $service = app(ResponseSelectionService::class);
        $request = Request::create('/?x=1', 'POST', [], [], [], ['HTTP_X_TENANT' => 'blue'], '{"user":{"role":"admin"}}');
        self::assertSame($higher->id, $service->select($endpoint, $request, $environment)->response->id);
        $request = Request::create('/?x=1', 'POST', [], [], [], ['HTTP_X_TENANT' => 'blue'], '{"user":{"role":"guest"}}');
        self::assertSame($lower->id, $service->select($endpoint, $request, $environment)->response->id);
        self::assertSame($fallback->id, $service->select($endpoint, Request::create('/'), $environment)->response->id);
        self::assertSame(3, EndpointCallState::query()->sole()->total_match_count);
    }

    public function test_contains_regex_exists_and_missing_json_are_resilient(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'rule']);
        $environment = Environment::query()->firstOrFail();
        $fallback = $endpoint->responses()->create(['is_default' => true]);
        $response = $endpoint->responses()->create([]);
        foreach ([
            ['field_type' => 'header', 'field_name' => 'X-Name', 'operator' => 'contains', 'value' => 'ada', 'priority' => 0],
            ['field_type' => 'query', 'field_name' => 'code', 'operator' => 'regex', 'value' => '/^AB[0-9]+$/', 'priority' => 1],
            ['field_type' => 'body_json_path', 'field_name' => 'user.id', 'operator' => 'exists', 'value' => null, 'priority' => 2],
        ] as $rule) {
            $response->rules()->create($rule);
        }
        $service = app(ResponseSelectionService::class);
        $request = Request::create('/?code=AB123', 'POST', [], [], [], ['HTTP_X_NAME' => 'ada lovelace'], '{"user":{"id":null}}');
        self::assertSame($response->id, $service->select($endpoint, $request, $environment)->response->id);
        $request = Request::create('/?code=AB123', 'POST', [], [], [], ['HTTP_X_NAME' => 'ada'], '{"user":{}}');
        self::assertSame($fallback->id, $service->select($endpoint, $request, $environment)->response->id);
        $response->rules()->where('operator', 'regex')->update(['value' => '/invalid[']);
        self::assertSame($fallback->id, $service->select($endpoint, $request, $environment)->response->id);
    }

    public function test_rule_configuration_requires_exactly_one_fallback_and_valid_rules(): void
    {
        $validator = app(SelectionConfigurationValidator::class);
        foreach ([[], [['is_default' => false]], [['is_default' => true], ['is_default' => true]]] as $responses) {
            try {
                $validator->validate(['selection_mode' => 'rule'], $responses);
                self::fail('Missing or duplicate fallback must be rejected.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('is_default', $exception->errors());
            }
        }
        $validator->validate(['selection_mode' => 'rule'], [['is_default' => true]]);
        $this->expectException(ValidationException::class);
        $validator->validate(['selection_mode' => 'rule'], [['is_default' => true, 'response_rules' => [
            ['field_type' => 'header', 'field_name' => 'X', 'operator' => 'regex', 'value' => 'bad', 'priority' => 0],
        ]]]);
    }

    public function test_sequence_configuration_rejects_gaps_and_duplicate_positions(): void
    {
        $validator = app(SelectionConfigurationValidator::class);
        $validator->validate(['selection_mode' => 'sequence'], [['sequence_order' => 1], ['sequence_order' => 0]]);
        $this->expectException(ValidationException::class);
        $validator->validate(['selection_mode' => 'sequence'], [['sequence_order' => 0], ['sequence_order' => 0]]);
    }

    private function sequence(string $exhaust = 'repeat_last'): array
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => $exhaust]);
        $responses = [];
        // Creation order intentionally differs from configured sequence order.
        foreach ([2, 0, 1] as $position) {
            $responses[$position] = $endpoint->responses()->create(['sequence_order' => $position, 'body' => (string) $position]);
        }

        return [$endpoint, Environment::query()->firstOrFail(), $responses];
    }
}
