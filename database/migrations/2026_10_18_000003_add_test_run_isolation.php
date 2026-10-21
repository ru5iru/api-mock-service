<?php

use App\Services\Curl\CurlParser;
use App\Services\TestRuns\RunRouting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never silently take an existing operator route away during an upgrade.
        foreach (DB::table('mock_endpoints')->select(['id', 'raw_curl', 'normalized_curl'])->cursor() as $endpoint) {
            $path = explode('?', explode("\n", $endpoint->normalized_curl)[1] ?? '/', 2)[0];
            if (RunRouting::reservedPath($path)) {
                throw new RuntimeException("Endpoint {$endpoint->id} uses a newly reserved /_mockdeck or /mcp path. Rename it with the previous version before retrying migration.");
            }
            try {
                RunRouting::validateConfiguration(app(CurlParser::class)->parse($endpoint->raw_curl));
            } catch (InvalidArgumentException $exception) {
                // Invalid legacy curl is not a new collision; only reserve routing metadata.
                if (str_contains($exception->getMessage(), 'routing metadata') || str_contains($exception->getMessage(), 'reserved')) {
                    throw new RuntimeException("Endpoint {$endpoint->id}: ".$exception->getMessage(), previous: $exception);
                }
            }
        }
        Schema::create('test_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('credential_id')->constrained('mcp_credentials')->cascadeOnDelete();
            $table->string('label');
            $table->foreignId('environment_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['active', 'finished'])->default('active');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
        });
        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->foreignId('test_run_id')->nullable()->constrained('test_runs')->cascadeOnDelete();
        });
        Schema::table('endpoint_call_state', function (Blueprint $table): void {
            $table->dropUnique(['endpoint_id', 'environment_id']);
            $table->foreignId('test_run_id')->nullable()->constrained('test_runs')->cascadeOnDelete();
        });
        // PostgreSQL/SQLite treat NULLs as distinct in a normal composite UNIQUE.
        // Partial indexes preserve the ordinary first-call insert/lock invariant too.
        DB::statement('CREATE UNIQUE INDEX call_state_ordinary_unique ON endpoint_call_state (endpoint_id, environment_id) WHERE test_run_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX call_state_run_unique ON endpoint_call_state (endpoint_id, environment_id, test_run_id) WHERE test_run_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::table('mock_endpoints')->whereNotNull('test_run_id')->delete();
        DB::table('endpoint_call_state')->whereNotNull('test_run_id')->delete();
        DB::statement('DROP INDEX call_state_ordinary_unique');
        DB::statement('DROP INDEX call_state_run_unique');
        Schema::table('endpoint_call_state', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('test_run_id');
            $table->unique(['endpoint_id', 'environment_id']);
        });
        Schema::table('mock_endpoints', fn (Blueprint $table) => $table->dropConstrainedForeignId('test_run_id'));
        Schema::dropIfExists('test_runs');
    }
};
