<?php

use App\Models\MockEndpoint;
use App\Models\Tag;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Contracts\Console\Kernel;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "Use a dedicated fixture database.\n");
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
MockEndpoint::query()->where('name', 'like', 'Registry alignment %')->get()->each->delete();
$tag = Tag::query()->firstOrCreate(['name' => 'abc']);
$unused = Tag::query()->firstOrCreate(['name' => 'Unused draft tag']);
$ids = [];
foreach ([[0, 0, 1], [40, 1, 9], [298, 12, 10], [16384, 32, 100], [1048576, 128, 101]] as $index => [$bytes, $headers, $responses]) {
    $raw = "curl --request POST 'https://api.example.test/registry-alignment/{$index}/".($index === 2 ? str_repeat('long-path-', 15) : 'items')."'";
    foreach (range(1, $headers ?: 1) as $number) {
        if ($headers) {
            $raw .= " --header 'X-Header-{$number}: value'";
        }
    }
    if ($bytes) {
        $raw .= " --data-raw '".str_repeat('x', $bytes)."'";
    }
    $variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($raw), false, false, false);
    $endpoint = MockEndpoint::factory()->create([
        'name' => 'Registry alignment '.$index, 'method' => 'POST', 'raw_curl' => $raw,
        'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
        'exclude_headers' => false, 'exclude_auth' => false,
        'enabled' => $index % 2 === 0, 'priority' => $index === 2 ? 99 : 0,
        'updated_at' => now()->subDays($index),
    ]);
    foreach (range(1, $responses) as $number) {
        $endpoint->responses()->create(['body' => '{}', 'callback_enabled' => $index === 2 && $number === 1]);
    }
    if ($index < 2) {
        $endpoint->tags()->attach($tag);
    }
    $ids[] = $endpoint->id;
}
echo json_encode(['endpoints' => $ids, 'tag' => $tag->id, 'unused_tag' => $unused->id], JSON_THROW_ON_ERROR);
