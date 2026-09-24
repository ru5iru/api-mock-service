<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->string('body_mode', 16)->default('static')->after('body');
            $table->longText('template')->nullable()->after('body_mode');
            $table->string('editor_view', 16)->default('builder')->after('template');
            $table->string('seed_mode', 16)->default('random')->after('editor_view');
            $table->integer('seed')->nullable()->after('seed_mode');
            $table->string('locale', 32)->default('en')->after('seed');
        });
    }

    public function down(): void
    {
        Schema::table('mock_responses', function (Blueprint $table): void {
            $table->dropColumn(['body_mode', 'template', 'editor_view', 'seed_mode', 'seed', 'locale']);
        });
    }
};
