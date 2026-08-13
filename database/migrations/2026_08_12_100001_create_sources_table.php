<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medios de origen. Una fuente que falla se marca inactiva y se registra el
 * motivo; nunca voltea el lote completo (CLAUDE.md §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('base_url');
            $table->string('spider_class')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_scraped_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('last_failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
