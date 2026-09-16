<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mock_endpoint_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('status_code')->default(200);
            $table->json('headers')->nullable();
            $table->longText('body')->nullable();
            $table->unsignedInteger('delay_ms')->default(0);
            $table->unsignedInteger('weight')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_responses');
    }
};
