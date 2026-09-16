<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('method', 10)->index();
            $table->text('raw_curl');
            $table->text('normalized_curl');
            $table->char('curl_hash', 64)->index();
            $table->boolean('exclude_cookies')->default(false);
            $table->boolean('exclude_auth')->default(false);
            $table->boolean('exclude_headers')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_endpoints');
    }
};
