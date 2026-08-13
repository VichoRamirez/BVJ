<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicación individual scrapeada. `url_hash` es sha256 de la URL normalizada
 * (App\Support\CanonicalUrl) y es lo que hace idempotente al pipeline:
 * reprocesar el mismo artículo actualiza la fila en vez de duplicarla.
 *
 * `content` guarda el mínimo necesario para analizar; no se republica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 2048);
            $table->string('url_hash', 64)->unique();
            $table->string('title', 512);
            $table->string('author')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->text('excerpt')->nullable();
            $table->text('content')->nullable();
            $table->timestamp('scraped_at')->nullable();
            $table->string('analysis_status')->default('pending')->index();
            $table->timestamps();

            $table->index(['event_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
