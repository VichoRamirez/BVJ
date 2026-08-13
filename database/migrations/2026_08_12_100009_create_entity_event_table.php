<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entidades a nivel de acontecimiento. Se sincroniza desde los artículos del
 * cluster (Event::syncAggregatesFromArticles) para que la portada pueda cargar
 * `events.entities` en una consulta, sin recorrer artículo por artículo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_event', function (Blueprint $table): void {
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->primary(['entity_id', 'event_id']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_event');
    }
};
