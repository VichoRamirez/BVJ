<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Spiders\BbcBusinessSpider;
use App\Spiders\DiarioFinancieroSpider;
use App\Spiders\PulsoSpider;
use Illuminate\Database\Seeder;

/**
 * Fuentes configuradas del MVP.
 *
 * **Solo se activa lo que tiene spider implementado.** Una fuente activa sin
 * `spider_class` no recolecta nada y solo suma fallos en cada corrida, así que
 * las que todavía no tienen araña quedan inactivas con el motivo escrito. A
 * medida que se implementen sus spiders, se les asigna la clase y se activan.
 *
 * Reuters queda inactiva también por otro motivo —responde 403—, y es lo que
 * hace visible el aviso de <x-source-status> en la portada.
 */
class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $pendingSpider = 'Sin araña implementada todavía: la fuente no expone un RSS utilizable y falta escribir su spider.';

        $sources = [
            [
                'name' => 'BBC News · Business',
                'slug' => 'bbc-business',
                'base_url' => 'https://www.bbc.com/business',
                'spider_class' => BbcBusinessSpider::class,
                'is_active' => true,
                'failure_count' => 0,
                'last_failure_reason' => null,
            ],
            [
                'name' => 'Diario Financiero',
                'slug' => 'diario-financiero',
                'base_url' => 'https://www.df.cl/mercados',
                'spider_class' => DiarioFinancieroSpider::class,
                'is_active' => true,
                'failure_count' => 0,
                'last_failure_reason' => null,
            ],
            [
                'name' => 'Bloomberg Línea',
                'slug' => 'bloomberg-linea',
                'base_url' => 'https://www.bloomberglinea.com',
                'spider_class' => null,
                'is_active' => false,
                'failure_count' => 0,
                'last_failure_reason' => $pendingSpider,
            ],
            [
                'name' => 'Pulso · La Tercera',
                'slug' => 'pulso',
                'base_url' => 'https://www.latercera.com/canal/pulso/',
                'spider_class' => PulsoSpider::class,
                'is_active' => true,
                'failure_count' => 0,
                'last_failure_reason' => null,
            ],
            [
                'name' => 'El Mercurio Inversiones',
                'slug' => 'mercurio-inversiones',
                'base_url' => 'https://www.elmercurio.com/inversiones',
                'spider_class' => null,
                'is_active' => false,
                'failure_count' => 1,
                'last_failure_reason' => $pendingSpider,
            ],
            [
                'name' => 'Reuters',
                'slug' => 'reuters',
                'base_url' => 'https://www.reuters.com',
                'spider_class' => null,
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
