<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->boolean('enabled')->default(true)->after('name');
            $table->smallInteger('priority')->default(0)->after('enabled');
            $table->unsignedSmallInteger('signature_version')->default(2)->after('curl_hash');

            $table->index(['enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->dropIndex(['enabled', 'priority']);
            $table->dropColumn(['enabled', 'priority', 'signature_version']);
        });
    }
};
