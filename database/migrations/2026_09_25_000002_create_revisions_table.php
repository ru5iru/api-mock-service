<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->string('source', 24);
            $table->uuid('import_batch_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->unique(['entity_type', 'entity_id', 'version_number']);
            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
