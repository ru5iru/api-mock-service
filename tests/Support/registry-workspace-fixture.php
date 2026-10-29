<?php

use App\Models\Collection;
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
$owned = fn () => MockEndpoint::query()->where(fn ($query) => $query
    ->where('name', 'like', 'Registry workspace %')->orWhere('name', 'like', 'Copy of Registry workspace %'));
if (($argv[1] ?? '') === 'inspect') {
    echo $owned()->with('tags')->orderBy('name')->get()->map(fn ($row) => [
        'id' => $row->id, 'name' => $row->name, 'enabled' => $row->enabled,
        'collection_id' => $row->collection_id, 'tags' => $row->tags->pluck('name'),
    ])->toJson();
    exit;
}
$owned()->get()->each->delete();
if (MockEndpoint::query()->exists()) {
    throw new RuntimeException('Use a fresh disposable database without other fixture endpoints.');
}
if (($argv[1] ?? '') === 'clear') {
    exit;
}
$collection = Collection::query()->firstOrCreate(['name' => 'Registry workspace Payments']);
$empty = Collection::query()->firstOrCreate(['name' => 'Registry workspace Empty']);
$tag = Tag::query()->firstOrCreate(['name' => 'Registry workspace Retry']);
Tag::query()->firstOrCreate(['name' => 'Registry workspace Reviewed']);
$ids = [];
foreach (range(1, 18) as $index) {
    $method = $index % 2 === 0 ? 'POST' : 'GET';
    $raw = "curl --request {$method} 'https://example.test/registry-workspace/{$index}'";
    $variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($raw), false, false, true);
    $endpoint = MockEndpoint::factory()->create([
        'name' => 'Registry workspace '.sprintf('%02d', $index).($index === 1 ? ' — '.str_repeat('long endpoint identity ', 5) : ''),
        'raw_curl' => $raw, 'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
        'method' => $method, 'enabled' => $index % 2 === 1, 'priority' => $index,
        'collection_id' => $index <= 3 ? $collection->id : null, 'updated_at' => now()->subMinutes($index),
    ]);
    if ($index <= 2) {
        $endpoint->tags()->attach($tag);
    }
    if ($index !== 2) {
        $endpoint->responses()->create(['body' => '{"registry":true}']);
    }
    $ids[] = $endpoint->id;
}
echo json_encode(['ids' => $ids, 'collection' => $collection->id, 'empty' => $empty->id, 'tag' => $tag->id], JSON_THROW_ON_ERROR);
