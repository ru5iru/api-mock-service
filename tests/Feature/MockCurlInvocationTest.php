<?php

namespace Tests\Feature;

use App\Http\Controllers\MockInvocationController;
use App\Models\MockEndpoint;
use App\Services\Config\ImportMode;
use App\Services\Curl\CurlParser;
use App\Services\Curl\MockCurlBuilder;
use App\Services\Curl\ParsedCurl;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MockCurlInvocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_request_without_content_type_matches_the_copied_curl_on_the_wire(): void
    {
        $this->assertCopiedCurlMatches('POST', '/email', [], "{\r\n  \"email\": \"operator@example.test\"\r\n}\r\n// retained comment");
    }

    public function test_copied_curl_preserves_get_with_body_and_literal_at_prefix(): void
    {
        $this->assertCopiedCurlMatches('GET', '/lookup', [['key' => 'Content-Type', 'value' => 'text/plain']], '@literal-body-not-a-file');
    }

    public function test_copied_curl_preserves_a_literal_partial_segment_variable(): void
    {
        $this->assertCopiedCurlMatches('GET', '/v{{version}}/users', [], '');
    }

    private function assertCopiedCurlMatches(string $method, string $path, array $headers, string $body): void
    {
        $json = json_encode([
            'info' => ['name' => 'Wire regression', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [[
                'name' => 'Example',
                'request' => ['method' => $method, 'url' => 'https://source.test'.$path, 'header' => $headers, 'body' => ['mode' => 'raw', 'raw' => $body]],
                'response' => [['name' => 'Saved example', 'code' => 200, 'header' => [
                    ['key' => 'Content-Type', 'value' => 'application/json'],
                    ['key' => 'Transfer-Encoding', 'value' => 'chunked'],
                    ['key' => 'Content-Encoding', 'value' => 'gzip'],
                    ['key' => 'Content-Length', 'value' => '9999'],
                    ['key' => 'Connection', 'value' => 'keep-alive'],
                ], 'body' => '{"mocked":true}']],
            ]],
        ], JSON_THROW_ON_ERROR);
        $imports = app(MappedImportService::class);
        $plan = $imports->preview(app(PostmanMapper::class), $json, ImportMode::CreateOnly);
        self::assertSame([], $plan->errors);
        $imports->apply($plan->token, $plan->digest);
        $endpoint = MockEndpoint::query()->sole();
        $wire = $this->sendCopiedCurl(app(CurlParser::class)->parse($endpoint->raw_curl));
        self::assertSame($method, $wire['method']);
        self::assertSame($body, base64_decode($wire['body']));

        $request = Request::create('http://localhost'.$wire['uri'], $wire['method'], [], [], [], [], base64_decode($wire['body']));
        // Request::create supplies a form Content-Type for POST; replace its
        // synthetic headers with exactly those received from the real curl.
        $request->headers->replace($wire['headers']);
        $reply = app(MockInvocationController::class)($request);
        self::assertSame(200, $reply->getStatusCode(), $reply->getContent());
        self::assertSame(['mocked' => true], json_decode($reply->getContent(), true));
        self::assertFalse($reply->headers->has('Transfer-Encoding'));
        self::assertFalse($reply->headers->has('Content-Encoding'));
        self::assertFalse($reply->headers->has('Content-Length'));
        self::assertFalse($reply->headers->has('Connection'));
    }

    private function sendCopiedCurl(ParsedCurl $request): array
    {
        // Bind an ephemeral local port; the receiver never exposes the app DB.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, base_path('tests/Fixtures/curl-wire-capture.php')]);
        $server->start();
        try {
            $ready = false;
            for ($i = 0; $i < 100 && $server->isRunning(); $i++) {
                $probe = @stream_socket_client('tcp://'.$address, $errno, $error, .1);
                if ($probe !== false) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            self::assertTrue($ready, $server->getErrorOutput());
            config(['app.url' => 'http://'.$address]);
            $command = app(MockCurlBuilder::class)->build($request);
            $curl = Process::fromShellCommandline($command.' --silent --show-error --max-time 5 --noproxy "*"');
            $curl->mustRun();

            return json_decode($curl->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $server->stop();
        }
    }
}
