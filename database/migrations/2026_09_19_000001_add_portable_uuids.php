<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique();
        });

        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique();
        });

        $this->backfill('mock_endpoints');
        $this->backfill('mock_responses');

        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
        });

        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });

        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }

    private function backfill(string $table): void
    {
        DB::table($table)
            ->whereNull('uuid')
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid7()]);
                }
            });
    }
};
