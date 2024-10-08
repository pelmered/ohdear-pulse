<?php

namespace OhDear\OhDearPulse\Livewire;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use OhDear\OhDearPulse\Livewire\Concerns\RemembersApiCalls;
use OhDear\OhDearPulse\Livewire\Concerns\UsesOhDearApi;
use OhDear\OhDearPulse\OhDearPulse;
use OhDear\PhpSdk\Resources\Check;
use OhDear\PhpSdk\Resources\PerformanceRecord;
use OhDear\PhpSdk\Resources\Site;

#[Lazy]
class OhDearUptimePulseCardComponent extends Card
{
    use RemembersApiCalls;
    use UsesOhDearApi;

    public int $siteId;

    public Collection $sites;

    public array $performanceRecords;

    public int $maxPerformanceRecord = 0;

    protected function css()
    {
        return __DIR__.'/../../dist/output.css';
    }

    public function mount(?int $siteId = null)
    {
        $this->sites = collect();

        $this->siteId = $siteId ?? config('services.oh_dear.pulse.site_id');

        $this->fetchPerformanceRecords();
    }

    public function fetchPerformanceRecords(): void
    {
        $performanceRecords = $this->rememberApiCall(
            fn () => $this->ohDear()->performanceRecords(
                $this->siteId,
                Carbon::now()->subMinutes(20),
                Carbon::now(),
            ),
            'performance-records:'.$this->siteId,
            CarbonInterval::seconds(30),
        );

        $performanceRecords = collect($performanceRecords)
            ->map(function (PerformanceRecord $record) {
                $createdAt = Carbon::createFromFormat('Y-m-d H:i:s', $record->createdAt);

                return [
                    $createdAt->getTimestampMs(),
                    number_format($record->totalTimeInSeconds * 1000, 2),
                ];
            })->reverse()->values()->toArray();

        $this->performanceRecords = $performanceRecords;

        $this->maxPerformanceRecord = (int) ceil(collect($this->performanceRecords)
            ->max(fn (array $dataPoint) => (float) $dataPoint[1])) + 10;
    }

    protected function getLabels(): array
    {
        return collect($this->performanceRecords)
            ->map(function (array $dataPoint, int $index) {
                if ($index === 0) {
                    return 'Now';
                }

                return $index.' '.Str::plural('minute', $index).' ago';
            })
            ->toArray();
    }

    public function render(): Renderable
    {
        $site = $this->getSite();

        return view('ohdear-pulse::uptime', [
            'site' => $site,
            'status' => $this->getStatus($site),
            'statusColor' => $this->getStatusColor(),
            'performance' => $this->getPerformance($site),
            'isConfigured' => $this->isConfigured(),
        ]);
    }

    public function getSite(): ?Site
    {
        if (! OhDearPulse::isConfigured()) {
            return null;
        }

        $siteAttributes = $this->rememberApiCall(
            fn () => $this->ohDear()?->site($this->siteId)?->attributes,
            'site:'.$this->siteId,
            CarbonInterval::seconds(10),
        );

        return new Site($siteAttributes);
    }

    protected function getStatus(?Site $site): ?string
    {
        if (! $site) {
            return null;
        }

        if (! $check = $this->getCheck($site, 'uptime')) {
            return null;
        }

        return match ($check->summary) {
            'Up' => 'Online',
            default => $check->summary,
        };
    }

    protected function getStatusColor(): string
    {
        return match ($this->getStatus($this->getSite())) {
            'Online' => 'dark:bg-gradient-to-t dark:from-emerald-500 dark:to-emerald-400 bg-emerald-100 text-emerald-800 dark:border-emerald-300 dark:text-gray-900 dark:border-t',
            'Down' => 'dark:bg-gradient-to-t dark:from-rose-500 dark:to-rose-400 bg-rose-100 text-rose-800 dark:border-rose-300 dark:text-gray-900 dark:border-t',
            default => 'bg-gray-600',
        };
    }

    protected function getPerformance(?Site $site): ?string
    {
        if (! $site) {
            return null;
        }

        if (! $check = $this->getCheck($site, 'performance')) {
            return null;
        }

        return $check->summary;
    }

    protected function getCheck(Site $site, string $type): ?Check
    {
        return collect($site->checks)
            ->first(fn (Check $check) => $check->type === $type);
    }
}
