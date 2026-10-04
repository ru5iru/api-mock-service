<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoint_call_state', function (Blueprint $table): void {
            $table->json('recent_call_digests')->nullable();
        });
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::table('endpoint_call_state', fn (Blueprint $table) => $table->dropColumn('recent_call_digests'));
    }
};
