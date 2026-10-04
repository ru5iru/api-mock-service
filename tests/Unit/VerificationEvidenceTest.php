<?php

namespace Tests\Unit;

use App\Models\EndpointCallState;
use App\Services\Response\RequestPredicateEvaluator;
use App\Services\Verification\CallAssertionService;
use App\Services\Verification\CallDigestRecorder;
use DateTimeImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class VerificationEvidenceTest extends TestCase
{
    public function test_digest_conditions_agree_with_live_rule_evaluation_for_scalar_null_array_and_dotted_keys(): void
    {
        $request = Request::create('/?code=ABC&list[]=one', 'POST', [], [], [], ['HTTP_X_MODE' => 'blue'], '{"user":{"name":"nested","active":true,"null":null},"user.name":"direct","array":[1,2]}');
        $predicates = new RequestPredicateEvaluator;
        $recorder = new CallDigestRecorder($predicates);
        $state = new EndpointCallState(['total_match_count' => 1, 'recent_call_digests' => [$recorder->capture($request, new DateTimeImmutable)]]);
        $assertions = new CallAssertionService($predicates);
        foreach ([
            ['header', 'X-MODE', 'equals', 'blue'], ['header', 'X-MODE', 'contains', 'lu'], ['header', 'missing', 'exists', null],
            ['query', 'code', 'regex', '/^AB/'], ['query', 'list', 'exists', null], ['query', 'list', 'equals', 'one'],
            ['body_json_path', 'user.name', 'equals', 'direct'], ['body_json_path', 'user.name', 'equals', 'nested'],
            ['body_json_path', 'user.active', 'equals', 'true'], ['body_json_path', 'user.null', 'exists', null],
            ['body_json_path', 'user.null', 'equals', ''], ['body_json_path', 'array.0', 'equals', '1'], ['body_json_path', 'array', 'exists', null],
        ] as [$field, $name, $operator, $value]) {
            $condition = ['field_type' => $field, 'field_name' => $name, 'operator' => $operator, 'value' => $value];
            $expected = $predicates->matches($condition, $request) ? 1 : 0;
            $result = $assertions->evaluate($state, [$condition], 'equals', $expected);
            self::assertTrue($result['passed'], json_encode($condition));
            self::assertSame($expected, $result['actual_count']);
        }
    }

    public function test_field_limit_and_body_bound_make_omitted_evidence_indeterminate(): void
    {
        $query = [];
        foreach (range(0, 70) as $index) {
            $query['field'.$index] = (string) $index;
        }
        $request = Request::create('/?'.http_build_query($query), 'POST', [], [], [], [], json_encode(['large' => str_repeat('a', 65536)]));
        $recorder = new CallDigestRecorder(new RequestPredicateEvaluator);
        $digest = $recorder->capture($request, new DateTimeImmutable);
        self::assertCount(64, $digest['query_digest']['fields']);
        self::assertFalse($digest['query_digest']['complete']);
        self::assertFalse($digest['body_digest_or_snippet']['complete']);
        self::assertSame([], $digest['body_digest_or_snippet']['fields']);
        $state = new EndpointCallState(['total_match_count' => 1, 'recent_call_digests' => [$digest]]);
        foreach ([['query', 'field70'], ['body_json_path', 'large']] as [$type, $name]) {
            $result = (new CallAssertionService(new RequestPredicateEvaluator))->evaluate($state, [['field_type' => $type, 'field_name' => $name, 'operator' => 'exists']], 'equals', 0);
            self::assertSame('indeterminate', $result['verdict']);
            self::assertSame(['minimum' => 0, 'maximum' => 1], $result['count_bounds']);
        }
    }

    public function test_deep_json_projections_are_bounded_and_nested_sensitive_values_never_store_plaintext(): void
    {
        $json = ['password' => ['value' => 'do-not-retain'], 'large' => array_fill(0, 100, 'value')];
        $request = Request::create('/', 'POST', [], [], [], [], json_encode($json));
        $digest = (new CallDigestRecorder(new RequestPredicateEvaluator))->capture($request, new DateTimeImmutable);
        self::assertCount(64, $digest['body_digest_or_snippet']['fields']);
        self::assertFalse($digest['body_digest_or_snippet']['complete']);
        self::assertStringNotContainsString('do-not-retain', json_encode($digest));
        self::assertSame('sensitive', $digest['body_digest_or_snippet']['fields']['password.value']['omitted']);
    }

    public function test_non_utf8_request_metadata_and_values_are_safe_for_json_storage(): void
    {
        $request = Request::create('/bad'.chr(255), 'GET', [], [], [], ['HTTP_X_VALUE' => chr(255)]);
        $digest = (new CallDigestRecorder(new RequestPredicateEvaluator))->capture($request, new DateTimeImmutable);
        self::assertIsString(json_encode($digest, JSON_THROW_ON_ERROR));
        self::assertSame('size_or_encoding', $digest['header_digest']['fields']['x-value']['omitted']);
    }

    public function test_pre_upgrade_counter_without_a_ring_stays_exact_only_for_unfiltered_assertions(): void
    {
        $state = new EndpointCallState(['total_match_count' => 12, 'recent_call_digests' => null]);
        $assertions = new CallAssertionService(new RequestPredicateEvaluator);
        self::assertTrue($assertions->evaluate($state, [], 'equals', 12)['passed']);
        $result = $assertions->evaluate($state, [['field_type' => 'header', 'field_name' => 'x', 'operator' => 'exists']], 'equals', 0);
        self::assertSame('indeterminate', $result['verdict']);
        self::assertSame(['minimum' => 0, 'maximum' => 12], $result['count_bounds']);
    }
}
