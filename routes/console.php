<?php

use App\Enums\BriefingEdition;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Pipeline de coyuntura
|--------------------------------------------------------------------------
|
| Las dos ediciones diarias (PLAN.md §4.3). La zona horaria es explícita: el
| servidor guarda en UTC, pero "las 07:00" significa las 07:00 en Chile.
|
| `withoutOverlapping` con expiración de una hora evita que una corrida lenta se
| pise con la siguiente y, si el proceso muere sin soltar el candado, este no
| queda tomado para siempre.
|
| Los horarios no se escriben a mano: salen del propio enum, que es lo mismo que
| usa GenerateBriefingJob para fechar la edición.
|
*/

foreach (BriefingEdition::cases() as $edition) {
    Schedule::command('news:pipeline', ['--edition' => $edition->value])
        ->dailyAt(sprintf('%02d:00', $edition->scheduledHour()))
        ->timezone(config('newsscraper.briefing.timezone'))
        ->withoutOverlapping(60)
        ->onOneServer()
        ->runInBackground();
}
