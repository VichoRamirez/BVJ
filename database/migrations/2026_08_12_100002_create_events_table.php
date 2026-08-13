<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un acontecimiento agrupa los artículos de distintas fuentes que hablan de lo
 * mismo. Es la unidad que se muestra en el briefing, así que lleva
 * denormalizados el resumen, la explicación y la relevancia del análisis líder:
 * las vistas leen la fila directo, sin joins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title', 512);
            $table->text('summary');
            $table->text('importance');
            $table->string('category')->index();
            $table->string('relevance');
            $table->unsignedSmallInteger('relevance_score')->default(0);
            $table->json('tags')->nullable();
            $table->timestamp('first_seen_at')->index();
            $table->unsignedInteger('articles_count')->default(0);
            $table->timestamps();

            $table->index(['relevance_score', 'first_seen_at'], 'events_score_seen_index');
            $table->index(['category', 'relevance_score'], 'events_category_score_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
