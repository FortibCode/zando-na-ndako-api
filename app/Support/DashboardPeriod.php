<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Résout un paramètre `period` (today, yesterday, 7d, 30d, 3m, year, custom) en bornes de dates
 * réelles, plus la période précédente de même durée (utilisée pour le calcul d'évolution en %).
 */
class DashboardPeriod
{
    public const PERIODS = ['today', 'yesterday', '7d', '30d', '3m', 'year', 'custom'];

    private function __construct(
        public readonly string $period,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $previousStart,
        public readonly Carbon $previousEnd,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $period = $request->get('period', '7d');
        if (! in_array($period, self::PERIODS, true)) {
            $period = '7d';
        }
        $now = Carbon::now();

        [$start, $end] = match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            '3m' => [$now->copy()->subMonths(3)->addDay()->startOfDay(), $now->copy()->endOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'custom' => self::customRange($request, $now),
        };

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $days = $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        return new self($period, $start, $end, $previousStart, $previousEnd);
    }

    private static function customRange(Request $request, Carbon $now): array
    {
        $start = $request->get('start');
        $end = $request->get('end');

        try {
            $start = $start ? Carbon::parse($start)->startOfDay() : $now->copy()->subDays(6)->startOfDay();
        } catch (\Throwable) {
            $start = $now->copy()->subDays(6)->startOfDay();
        }

        try {
            $end = $end ? Carbon::parse($end)->endOfDay() : $now->copy()->endOfDay();
        } catch (\Throwable) {
            $end = $now->copy()->endOfDay();
        }

        return [$start, $end];
    }

    /** Granularité d'agrégation temporelle : jour au-delà, semaine pour les longues périodes. */
    public function granularity(): string
    {
        return $this->start->diffInDays($this->end) > 60 ? 'week' : 'day';
    }

    public function cacheKeyParts(): array
    {
        return [$this->period, $this->start->toDateString(), $this->end->toDateString()];
    }

    /** TTL court pour les périodes qui bougent en continu (aujourd'hui), plus long sinon. */
    public function ttl(): int
    {
        return in_array($this->period, ['today', 'yesterday'], true) ? 60 : 300;
    }
}
