<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orden de presentación de los acontecimientos dentro de una edición. La
 * posición 1 es el titular, y el orden se resuelve en SQL desde el pivote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('briefing_event', function (Blueprint $table): void {
            $table->foreignId('briefing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');

            $table->primary(['briefing_id', 'event_id']);
            $table->unique(['briefing_id', 'position']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefing_event');
    }
};
