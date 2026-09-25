<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->foreignId('collection_id')->nullable()->after('uuid')->constrained('collections')->nullOnDelete();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->timestamps();
        });

        Schema::create('endpoint_tags', function (Blueprint $table): void {
            $table->foreignId('endpoint_id')->constrained('mock_endpoints')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['endpoint_id', 'tag_id']);
        });

        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('environment_variables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
            $table->unique(['environment_id', 'key']);
        });

        Schema::create('endpoint_environment_overrides', function (Blueprint $table): void {
            $table->foreignId('endpoint_id')->constrained('mock_endpoints')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled');
            $table->timestamps();
            $table->primary(['endpoint_id', 'environment_id']);
        });

        Schema::create('app_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $now = now();
        $environmentId = DB::table('environments')->insertGetId([
            'name' => 'Development',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_settings')->insert([
            'key' => 'active_environment_id',
            'value' => (string) $environmentId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('endpoint_environment_overrides');
        Schema::dropIfExists('environment_variables');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('endpoint_tags');
        Schema::dropIfExists('tags');
        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('collection_id');
        });
        Schema::dropIfExists('collections');
    }
};
