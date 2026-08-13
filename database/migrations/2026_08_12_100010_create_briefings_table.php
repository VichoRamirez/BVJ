<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edición publicada. El unique garantiza una sola edición de mañana y una de
 * tarde por día. `published_on` es date (no datetime) porque las vistas agrupan
 * y comparan por día calendario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('briefings', function (Blueprint $table): void {
            $table->id();
            $table->string('edition');
            $table->date('published_on');
            $table->timestamp('published_at')->index();
            $table->timestamps();

            $table->unique(['published_on', 'edition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefings');
    }
};
