<?php

namespace App\Services\Stats;

use Illuminate\Database\Eloquent\Builder;

/**
 * Топ діяльностей за період: сумарний час по назві й продуктивності.
 */
class TopActivities
{
    private const TOP = 8;

    /**
     * @param  Builder  $entries  записи activity_entries, уже обмежені періодом і видимістю
     * @return list<array{name: string, category: ?string, productivity: string, seconds: int}>
     */
    public function summarize(Builder $entries): array
    {
        return $entries
            ->selectRaw('name, productivity, MAX(category) as category, SUM(duration_seconds) as seconds')
            ->groupBy('name', 'productivity')
            ->orderByDesc('seconds')
            ->limit(self::TOP)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'category' => $row->category,
                'productivity' => $row->productivity,
                'seconds' => (int) $row->seconds,
            ])
            ->all();
    }
}
