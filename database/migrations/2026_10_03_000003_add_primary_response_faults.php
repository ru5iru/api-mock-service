<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->boolean('fault_enabled')->default(false);
            $table->enum('fault_type', ['delay', 'malformed_body', 'truncated_body', 'timeout'])->default('delay');
            $table->unsignedInteger('fault_delay_ms_min')->default(0);
            $table->unsignedInteger('fault_delay_ms_max')->nullable();
            $table->unsignedTinyInteger('fault_probability')->default(100);
        });
    }

    public function down(): void
    {
        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->dropColumn(['fault_enabled', 'fault_type', 'fault_delay_ms_min', 'fault_delay_ms_max', 'fault_probability']);
        });
    }
};
