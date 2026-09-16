<?php

use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $parser = app(CurlParser::class);
        $hasher = app(CurlHasher::class);

        DB::table('mock_endpoints')->orderBy('id')->eachById(function (object $endpoint) use ($parser, $hasher): void {
            $variant = $hasher->forOptions(
                $parser->parse($endpoint->raw_curl),
                (bool) $endpoint->exclude_cookies,
                (bool) $endpoint->exclude_auth,
                (bool) $endpoint->exclude_headers,
            );

            DB::table('mock_endpoints')->where('id', $endpoint->id)->update([
                'normalized_curl' => $variant->normalized,
                'curl_hash' => $variant->hash,
            ]);
        });
    }

    public function down(): void
    {
        // Origin data remains available in raw_curl, but the retired canonical
        // format is intentionally not maintained by current application code.
    }
};
