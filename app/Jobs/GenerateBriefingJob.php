<?php

namespace App\Jobs;

use App\Enums\BriefingEdition;
use App\Enums\RelevanceLevel;
use App\Models\Briefing;
use App\Models\Event;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GenerateBriefingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries;

    public int $timeout;

    public int $overlapReleaseAfter;

    public int $overlapTtl;

    public function __construct(
        public readonly BriefingEdition $edition,
        public readonly string $editorialDate,
    ) {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $editorialDate)) {
            throw new InvalidArgumentException('editorialDate must use the Y-m-d format.');
        }

        try {
            [$year, $month, $day] = array_map('intval', explode('-', $editorialDate));
            /** @var CarbonImmutable $validatedDate */
            $validatedDate = CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, 'UTC');
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("Invalid editorial date: {$editorialDate}.", previous: $exception);
        }

        $this->tries = max((int) config('newsscraper.briefing.job_tries', 3), 3);
        $this->timeout = max((int) config('newsscraper.briefing.job_timeout', 120), 30);
        $this->overlapReleaseAfter = max(
            (int) config('newsscraper.briefing.overlap_release_after', $this->timeout),
            $this->timeout,
        );
        $this->overlapTtl = max(
            (int) config('newsscraper.briefing.overlap_ttl', $this->timeout + 31),
            $this->timeout + 31,
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('briefing:'.$this->edition->value.':'.$this->editorialDate))
                ->shared()
                ->releaseAfter($this->overlapReleaseAfter)
                ->expireAfter($this->overlapTtl),
        ];
    }

    public function handle(): void
    {
        $localDate = $this->editorialDateInConfiguredTimezone();
        $publishedAt = $localDate->setTime($this->edition->scheduledHour(), 0)->utc();

        $minimum = RelevanceLevel::tryFrom((string) config('newsscraper.relevance.minimum_for_briefing'));
        if ($minimum === null) {
            throw new InvalidArgumentException('Invalid minimum_for_briefing relevance level.');
        }

        $limit = (int) config('newsscraper.briefing.events_per_edition');
        if ($limit < 1 || $limit > 255) {
            throw new InvalidArgumentException('events_per_edition must be between 1 and 255.');
        }

        if (Briefing::query()
            ->whereDate('published_on', $localDate)
            ->where('edition', $this->edition->value)
            ->exists()) {
            return;
        }

        $previousPublishedAt = Briefing::query()
            ->where('edition', $this->edition->value)
            ->where('published_at', '<', $publishedAt)
            ->max('published_at');
        $start = $previousPublishedAt === null
            ? $publishedAt->subDay()
            : CarbonImmutable::parse($previousPublishedAt, 'UTC');

        $events = Event::query()
            ->where('first_seen_at', '>=', $start)
            ->where('first_seen_at', '<', $publishedAt)
            ->where('relevance_score', '>=', $minimum->weight() * 100)
            ->orderByDesc('relevance_score')
            ->orderByDesc('first_seen_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        try {
            DB::transaction(function () use ($events, $localDate, $publishedAt): void {
                $briefing = Briefing::query()->create([
                    'edition' => $this->edition,
                    'published_on' => $localDate,
                    'published_at' => $publishedAt,
                ]);

                $briefing->events()->attach($events->mapWithKeys(
                    fn (Event $event, int $index): array => [$event->id => ['position' => $index + 1]],
                )->all());
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! Briefing::query()
                ->whereDate('published_on', $localDate)
                ->where('edition', $this->edition->value)
                ->exists()) {
                throw $exception;
            }
        }
    }

    private function editorialDateInConfiguredTimezone(): CarbonImmutable
    {
        $timezone = (string) config('newsscraper.briefing.timezone');

        try {
            new DateTimeZone($timezone);
            [$year, $month, $day] = array_map('intval', explode('-', $this->editorialDate));

            /** @var CarbonImmutable $localDate */
            $localDate = CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, $timezone);

            return $localDate;
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                "Invalid briefing timezone or editorial date: {$timezone} / {$this->editorialDate}.",
                previous: $exception,
            );
        }
    }
}
