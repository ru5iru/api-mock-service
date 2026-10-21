<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('mcp_credentials')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('test_runs')->cascadeOnDelete();
            $table->string('tool_name', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->json('cached_result');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->unique(['credential_id', 'run_id', 'tool_name', 'idempotency_key'], 'mcp_idempotency_scope');
        });
        Schema::create('mcp_unmatched_requests', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->foreignId('run_id')->constrained('test_runs')->cascadeOnDelete();
            $table->text('request'); // encrypted; never returned directly
            $table->boolean('complete');
            $table->timestamp('created_at')->useCurrent()->index();
        });
        Schema::table('test_runs', fn (Blueprint $table) => $table->json('final_summary')->nullable());
        Schema::table('revisions', fn (Blueprint $table) => $table->foreignId('test_run_id')->nullable()->constrained('test_runs')->cascadeOnDelete());
    }

    public function down(): void
    {
        Schema::table('revisions', fn (Blueprint $table) => $table->dropConstrainedForeignId('test_run_id'));
        Schema::table('test_runs', fn (Blueprint $table) => $table->dropColumn('final_summary'));
        Schema::dropIfExists('mcp_unmatched_requests');
        Schema::dropIfExists('mcp_idempotency_keys');
    }
};
