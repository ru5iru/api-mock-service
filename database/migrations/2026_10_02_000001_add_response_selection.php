<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_endpoints', function (Blueprint $table): void {
            $table->enum('selection_mode', ['weighted', 'sequence', 'rule'])->default('weighted');
            $table->enum('sequence_on_exhaust', ['repeat_last', 'loop', 'not_found'])->nullable()->default('repeat_last');
        });
        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->integer('sequence_order')->nullable();
            $table->boolean('is_default')->default(false);
        });
        Schema::create('response_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('response_id')->constrained('mock_responses')->cascadeOnDelete();
            $table->enum('field_type', ['header', 'query', 'body_json_path']);
            $table->string('field_name');
            $table->enum('operator', ['equals', 'contains', 'regex', 'exists']);
            $table->text('value')->nullable();
            $table->integer('priority')->default(0)->index();
        });
        Schema::create('endpoint_call_state', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('mock_endpoints')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence_position')->default(0);
            $table->unsignedBigInteger('total_match_count')->default(0);
            $table->timestamp('last_matched_at')->nullable();
            $table->unique(['endpoint_id', 'environment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_call_state');
        Schema::dropIfExists('response_rules');
        Schema::table('mock_responses', fn (Blueprint $table) => $table->dropColumn(['sequence_order', 'is_default']));
        Schema::table('mock_endpoints', fn (Blueprint $table) => $table->dropColumn(['selection_mode', 'sequence_on_exhaust']));
    }
};
