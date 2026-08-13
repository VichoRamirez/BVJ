<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
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
            DemoSeeder::class,
        ]);
    }
}
