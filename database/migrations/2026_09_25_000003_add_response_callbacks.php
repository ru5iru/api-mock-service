<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->boolean('callback_enabled')->default(false);
            $table->text('callback_url')->nullable();
            $table->string('callback_method', 12)->default('POST');
            $table->json('callback_headers')->nullable();
            $table->longText('callback_body')->nullable();
            $table->unsignedInteger('callback_delay_ms')->default(0);
            $table->unsignedInteger('callback_delay_max_ms')->nullable();
            $table->unsignedTinyInteger('callback_retry')->default(1);
            $table->unsignedInteger('callback_backoff_ms')->default(1000);
            $table->unsignedSmallInteger('callback_timeout_ms')->default(5000);
            $table->boolean('callback_signing_enabled')->default(false);
            $table->text('callback_signing_secret')->nullable();
            $table->string('callback_signature_header')->default('X-MockDeck-Signature');
        });

        Schema::create('callback_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('response_id')->nullable()->constrained('mock_responses')->nullOnDelete();
            // Request logs are JSON files; this links by their stable X-Request-ID.
            $table->string('request_log_id', 128)->nullable()->index();
            $table->foreignId('resend_of_id')->nullable()->constrained('callback_attempts')->nullOnDelete();
            $table->foreignId('retry_of_id')->nullable()->constrained('callback_attempts')->nullOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->text('target_url');
            $table->string('method', 12);
            $table->longText('resolved_headers');
            $table->longText('resolved_body');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['response_id', 'created_at']);
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('callback_attempts');
        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->dropColumn([
                'callback_enabled', 'callback_url', 'callback_method', 'callback_headers', 'callback_body',
                'callback_delay_ms', 'callback_delay_max_ms', 'callback_retry', 'callback_backoff_ms',
                'callback_timeout_ms', 'callback_signing_enabled', 'callback_signing_secret',
                'callback_signature_header',
            ]);
        });
    }
};
