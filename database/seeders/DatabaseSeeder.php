<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Solo siembra las fuentes: las noticias las trae el pipeline real.
 *
 * `DemoSeeder` quedó fuera a propósito. Mientras estuvo acá, un
 * `migrate:fresh --seed` llenaba la base de acontecimientos y briefings
 * inventados, y costaba distinguir en la portada qué venía del scraping de
 * verdad y qué era relleno. Sigue existiendo y se corre a mano:
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * Es el plan B de la presentación (PLAN.md §5): si el scraping o la IA fallan
 * en vivo, esos datos dejan la aplicación presentable en un comando.
 *
 * Sin `WithoutModelEvents`: el `url_hash` de los artículos lo calcula el hook
 * `saving()` de App\Models\Article, y silenciando los eventos del modelo el
 * seeder moriría contra el unique de esa columna.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SourceSeeder::class,
        ]);
    }
}
