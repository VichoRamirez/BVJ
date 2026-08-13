<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salida estructurada del LLM para un artículo, 1:1.
 *
 * `raw_response` guarda siempre la respuesta cruda junto al análisis parseado,
 * para poder depurar alucinaciones (CLAUDE.md §4). Un análisis que no valida
 * contra el esquema no llega hasta acá: el artículo queda en `failed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('ollama');
            $table->string('model');
            $table->string('schema_version', 16)->default('1.0');
            $table->text('summary');
            $table->string('category')->index();
            $table->string('relevance');
            $table->text('importance_explanation');
            $table->json('raw_response');
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
