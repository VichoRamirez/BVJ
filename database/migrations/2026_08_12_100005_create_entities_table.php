<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empresas y personas mencionadas. El unique sobre (type, slug) es lo que evita
 * que "Codelco" y "CODELCO" terminen como dos entidades distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['type', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
