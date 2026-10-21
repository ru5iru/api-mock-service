<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_invocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('mcp_credentials')->restrictOnDelete();
            $table->string('tool_name')->nullable();
            $table->text('target')->nullable();
            $table->uuid('run_id')->nullable()->index();
            $table->enum('result', ['success', 'error']);
            $table->string('error_code', 32)->nullable();
            $table->uuid('correlation_id')->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_invocations');
    }
};
