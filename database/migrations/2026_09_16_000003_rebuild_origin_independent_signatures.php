<?php

use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $parser = app(CurlParser::class);
        $hasher = app(CurlHasher::class);
        $hasSignatureVersion = Schema::hasColumn('mock_endpoints', 'signature_version');

        DB::table('mock_endpoints')->orderBy('id')->eachById(function (object $endpoint) use ($parser, $hasher, $hasSignatureVersion): void {
            $variant = $hasher->forOptions(
                $parser->parse($endpoint->raw_curl),
                (bool) $endpoint->exclude_cookies,
                (bool) $endpoint->exclude_auth,
                (bool) $endpoint->exclude_headers,
            );

            $values = [
                'normalized_curl' => $variant->normalized,
                'curl_hash' => $variant->hash,
            ];

            if ($hasSignatureVersion) {
                $values['signature_version'] = 2;
            }

            DB::table('mock_endpoints')->where('id', $endpoint->id)->update($values);
        });
    }

    public function down(): void
    {
        // raw_curl retains the upstream origin, but current code intentionally
        // does not recreate the retired origin-dependent canonical format.
    }
};
