<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately do not rebuild canonical strings, hashes or legacy versions.
        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->json('excluded_query_params')->nullable();
            $table->json('excluded_headers')->nullable();
            $table->boolean('path_pattern_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mock_endpoints', fn (Blueprint $table) => $table->dropColumn(['excluded_query_params', 'excluded_headers', 'path_pattern_enabled']));
    }
};
