<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captura de un instrumento en un momento dado (Yahoo Finance). Los metadatos
 * de presentación van denormalizados para que la fila siga siendo legible
 * aunque cambie config('newsscraper.markets.instruments').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol')->index();
            $table->string('name');
            $table->string('detail')->nullable();
            $table->string('unit', 12)->nullable();
            $table->decimal('price', 16, 4);
            $table->decimal('change_percent', 8, 4);
            $table->json('history')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->unique(['symbol', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
    }
};
