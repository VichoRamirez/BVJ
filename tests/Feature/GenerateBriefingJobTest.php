<?php

use App\Enums\BriefingEdition;
use App\Jobs\GenerateBriefingJob;
use App\Models\Briefing;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

function generateBriefing(string $date, BriefingEdition $edition = BriefingEdition::Morning): void
{
    (new GenerateBriefingJob($edition, $date))->handle();
}

it('genera el briefing con orden y posiciones deterministas', function () {
    $date = CarbonImmutable::parse('2026-08-12', 'America/Santiago');
    Event::factory()->create(['relevance_score' => 401, 'first_seen_at' => $date->setTime(5, 0)->utc()]);
    Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => $date->setTime(6, 0)->utc()]);

    generateBriefing($date->toDateString());

    $briefing = Briefing::with('events')->firstOrFail();
    expect($briefing->edition)->toBe(BriefingEdition::Morning)
        ->and($briefing->published_on->toDateString())->toBe('2026-08-12')
        ->and($briefing->published_at->toImmutable()->utc()->toDateTimeString())->toBe('2026-08-12 11:00:00')
        ->and($briefing->events->pluck('relevance_score')->all())->toBe([401, 301])
        ->and($briefing->events->pluck('pivot.position')->all())->toBe([1, 2]);
});

it('desempata por fecha descendente y luego por id ascendente', function () {
    $date = CarbonImmutable::parse('2026-08-12', 'America/Santiago');
    $older = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => $date->setTime(5, 0)->utc()]);
    $newer = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => $date->setTime(6, 0)->utc()]);
    $sameTime = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => $date->setTime(6, 0)->utc()]);

    generateBriefing($date->toDateString());

    expect(Briefing::with('events')->firstOrFail()->events->pluck('id')->all())
        ->toBe([$newer->id, $sameTime->id, $older->id]);
});

it('aplica umbral, limite y ventana semiabierta', function () {
    config()->set('newsscraper.relevance.minimum_for_briefing', 'high');
    config()->set('newsscraper.briefing.events_per_edition', 2);
    $date = CarbonImmutable::parse('2026-08-12', 'America/Santiago');
    $previous = Briefing::factory()->morning()->on($date->subDay())->create([
        'published_at' => '2026-08-11 10:00:00',
    ]);
    $included = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => '2026-08-11 12:00:00']);
    $atStart = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => '2026-08-11 10:00:00']);
    $atEnd = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => '2026-08-12 11:00:00']);
    $belowThreshold = Event::factory()->create(['relevance_score' => 200, 'first_seen_at' => '2026-08-11 12:00:00']);

    generateBriefing($date->toDateString());

    $briefing = Briefing::query()->whereDate('published_on', $date)->with('events')->firstOrFail();

    expect($briefing->events->pluck('id')->all())->toBe([$included->id, $atStart->id])
        ->and($briefing->events->pluck('pivot.position')->all())->toBe([1, 2])
        ->and($briefing->events->pluck('id')->all())->not->toContain($atEnd->id)
        ->and($briefing->events->pluck('id')->all())->not->toContain($belowThreshold->id)
        ->and($briefing->events)->toHaveCount(2);
});

it('usa las últimas 24 horas en la primera edición', function () {
    $date = CarbonImmutable::parse('2026-08-12', 'America/Santiago');
    $inside = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => $date->setTime(22, 0)->subDay()->utc()]);
    Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => $date->setTime(16, 0)->subDay()->utc()]);

    generateBriefing($date->toDateString(), BriefingEdition::Evening);

    expect(Briefing::with('events')->firstOrFail()->events->pluck('id')->all())->toBe([$inside->id]);
});

it('no crea briefing sin eventos y es idempotente sin modificarlo', function () {
    $date = CarbonImmutable::parse('2026-08-12', 'America/Santiago');
    generateBriefing($date->toDateString());
    expect(Briefing::count())->toBe(0);

    $event = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => $date->setTime(6, 0)->utc()]);
    generateBriefing($date->toDateString());
    $briefing = Briefing::firstOrFail();
    $publishedAt = $briefing->published_at;
    generateBriefing($date->toDateString());

    expect(Briefing::count())->toBe(1)
        ->and($briefing->fresh()->published_at->equalTo($publishedAt))->toBeTrue()
        ->and($briefing->fresh()->events->first()->id)->toBe($event->id);
});

it('calcula fecha editorial explícita en timezone y oculta publicaciones futuras', function () {
    $date = CarbonImmutable::parse('2026-11-01', 'America/Santiago');
    $event = Event::factory()->create(['relevance_score' => 301, 'first_seen_at' => $date->setTime(4, 0)->utc()]);
    generateBriefing($date->toDateString(), BriefingEdition::Evening);
    $briefing = Briefing::firstOrFail();

    expect($briefing->published_on->toDateString())->toBe('2026-11-01')
        ->and($briefing->published_at->toImmutable()->utc()->toDateTimeString())->toBe('2026-11-01 21:00:00')
        ->and(Briefing::query()->published()->pluck('id')->all())->toBeEmpty();
});

it('valida configuración inválida y expone overlap específico', function () {
    config()->set('newsscraper.relevance.minimum_for_briefing', 'invalid');
    expect(fn () => generateBriefing('2026-08-12'))
        ->toThrow(InvalidArgumentException::class);

    config()->set('newsscraper.relevance.minimum_for_briefing', 'medium');
    config()->set('newsscraper.briefing.events_per_edition', 256);
    expect(fn () => generateBriefing('2026-08-12'))
        ->toThrow(InvalidArgumentException::class);

    config()->set('newsscraper.briefing.events_per_edition', 0);
    expect(fn () => generateBriefing('2026-08-12'))
        ->toThrow(InvalidArgumentException::class);

    config()->set('newsscraper.briefing.events_per_edition', 7);
    config()->set('newsscraper.briefing.timezone', 'not-a-timezone');
    expect(fn () => generateBriefing('2026-08-12'))
        ->toThrow(InvalidArgumentException::class, 'Invalid briefing timezone');

    $job = new GenerateBriefingJob(BriefingEdition::Morning, '2026-08-12');
    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(120)
        ->and($job->overlapReleaseAfter)->toBeGreaterThanOrEqual($job->timeout)
        ->and($job->overlapTtl)->toBeGreaterThan($job->timeout)
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('rechaza fechas editoriales con formato o calendario inválido', function (string $date, string $message) {
    expect(fn () => new GenerateBriefingJob(BriefingEdition::Morning, $date))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['2026-02-30', 'Invalid editorial date'],
    ['2026-2-03', 'Y-m-d format'],
    ['2026-13-01', 'Invalid editorial date'],
]);

it('mantiene retry_after por encima de los timeouts relevantes', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');
    $redisRetryAfter = (int) config('queue.connections.redis.retry_after');
    $beanstalkRetryAfter = (int) config('queue.connections.beanstalkd.retry_after');
    $largestTimeout = max(
        (int) config('newsscraper.ai.job_timeout'),
        (int) config('newsscraper.clustering.job_timeout'),
        (int) config('newsscraper.briefing.job_timeout'),
    );

    expect($retryAfter)->toBeGreaterThan($largestTimeout + 30);
    expect($redisRetryAfter)->toBeGreaterThan($largestTimeout + 30)
        ->and($beanstalkRetryAfter)->toBeGreaterThan($largestTimeout + 30);
});

it('mantiene la fecha editorial civil cerca de medianoche y durante DST', function () {
    config()->set('newsscraper.briefing.timezone', 'America/Santiago');
    $event = Event::factory()->create([
        'relevance_score' => 301,
        'first_seen_at' => '2026-10-31 23:30:00',
    ]);

    generateBriefing('2026-11-01', BriefingEdition::Morning);

    $briefing = Briefing::with('events')->firstOrFail();
    expect($briefing->published_on->toDateString())->toBe('2026-11-01')
        ->and($briefing->published_at->toImmutable()->utc()->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and($briefing->events->pluck('id')->all())->toBe([$event->id]);
});
