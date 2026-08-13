<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Fuentes configuradas del MVP.
 *
 * Reuters queda inactiva a propósito: es lo que hace visible el aviso de
 * <x-source-status> en la portada sin tener que romper nada.
 */
class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            [
                'name' => 'Diario Financiero',
                'slug' => 'diario-financiero',
                'base_url' => 'https://www.df.cl',
                'is_active' => true,
                'failure_count' => 0,
                'last_failure_reason' => null,
            ],
            [
                'name' => 'Bloomberg Línea',
                'slug' => 'bloomberg-linea',
                'base_url' => 'https://www.bloomberglinea.com',
                'is_active' => true,
                'failure_count' => 0,
                'last_failure_reason' => null,
            ],
            [
                'name' => 'Pulso · La Tercera',
                'slug' => 'pulso',
                'base_url' => 'https://www.latercera.com/pulso',
                'is_active' => true,
                'failure_count' => 0,
                'last_failure_reason' => null,
            ],
            [
                'name' => 'El Mercurio Inversiones',
                'slug' => 'mercurio-inversiones',
                'base_url' => 'https://www.elmercurio.com/inversiones',
                'is_active' => true,
                'failure_count' => 1,
                'last_failure_reason' => 'Tiempo de espera agotado en la última recolección.',
            ],
            [
                'name' => 'Reuters',
                'slug' => 'reuters',
                'base_url' => 'https://www.reuters.com',
                'is_active' => false,
                'failure_count' => 3,
                'last_failure_reason' => 'La fuente respondió 403 en las últimas tres corridas.',
            ],
        ];

        foreach ($sources as $source) {
            Source::updateOrCreate(
                ['slug' => $source['slug']],
                [...$source, 'last_scraped_at' => now()],
            );
        }
    }
}
