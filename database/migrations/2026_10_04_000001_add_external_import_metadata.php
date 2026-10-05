<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_endpoints', fn (Blueprint $table) => $table->json('external_source')->nullable());
        Schema::table('mock_responses', fn (Blueprint $table) => $table->string('external_label')->nullable());
    }

    public function down(): void
    {
        Schema::table('mock_responses', fn (Blueprint $table) => $table->dropColumn('external_label'));
        Schema::table('mock_endpoints', fn (Blueprint $table) => $table->dropColumn('external_source'));
    }
};
