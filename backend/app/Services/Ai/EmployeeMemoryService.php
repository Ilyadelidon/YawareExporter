<?php

namespace App\Services\Ai;

use App\Models\EmployeeMemory;
use Illuminate\Support\Carbon;

/**
 * Персональна пам'ять AI-аналітики: що вже з'ясовано про домени й застосунки
 * конкретного працівника.
 *
 * Наповнюється детерміновано — з готового розбору дня, а не окремим викликом
 * моделі: так видно, звідки взявся кожен рядок, і не треба платити за зайвий
 * запит. Читається назад у промпт, щоб модель не ходила в web_search за тим
 * самим доменом щодня (за замірами це ~2/3 вартості розбору).
 */
class EmployeeMemoryService
{
    /** Через скільки днів вердикт AI протухає і домен перевіряється заново. */
    public const RECHECK_DAYS = 90;

    /** Стеля рядків у промпті — інакше пам'ять із часом з'їсть усю економію. */
    public const MAX_ITEMS = 120;

    /**
     * Записує в пам'ять вердикти з готового розбору дня.
     *
     * @param  array<string, mixed>  $result  Результат за AnalysisPrompt::responseSchema()
     */
    public function remember(int $employeeId, array $result, string $date): void
    {
        foreach ($result['unclear_activities'] ?? [] as $activity) {
            $name = trim((string) ($activity['name'] ?? ''));
            $verdict = $activity['verdict'] ?? null;

            if ($name === '' || ! in_array($verdict, EmployeeMemory::VERDICTS, true)) {
                continue;
            }

            $memory = EmployeeMemory::firstOrNew([
                'employee_id' => $employeeId,
                'kind' => EmployeeMemory::KIND_ACTIVITY,
                'name' => $name,
            ]);

            // Рядок, виправлений адміністратором, модель не перезаписує —
            // лише оновлює статистику появи.
            if ($memory->source !== EmployeeMemory::SOURCE_ADMIN) {
                $memory->verdict = $verdict;
                $memory->note = mb_substr((string) ($activity['reasoning'] ?? ''), 0, 500);
                $memory->source = EmployeeMemory::SOURCE_AI;
                $memory->checked_at = now();
            }

            $memory->occurrences = ($memory->occurrences ?? 0) + 1;

            // Дні можуть перегенеровуватись у довільному порядку — тримаємо
            // найпізнішу дату, а не дату останнього запису.
            if (! $memory->last_seen_at || $memory->last_seen_at->toDateString() < $date) {
                $memory->last_seen_at = $date;
            }

            $memory->save();
        }
    }

    /**
     * Пам'ять для промпту: спершу те, що траплялось найчастіше.
     *
     * @return array{activities: list<array<string, mixed>>, facts: list<array<string, mixed>>}
     */
    public function forPrompt(int $employeeId): array
    {
        $stale = Carbon::now()->subDays(self::RECHECK_DAYS);

        $rows = EmployeeMemory::where('employee_id', $employeeId)
            // Протухлий вердикт AI не показуємо — хай перевірить заново.
            // Вердикт адміністратора не протухає.
            ->where(fn ($query) => $query
                ->where('source', EmployeeMemory::SOURCE_ADMIN)
                ->orWhere('checked_at', '>=', $stale))
            ->orderByDesc('occurrences')
            ->limit(self::MAX_ITEMS)
            ->get();

        return [
            'activities' => $rows
                ->where('kind', EmployeeMemory::KIND_ACTIVITY)
                ->map(fn (EmployeeMemory $memory) => [
                    'name' => $memory->name,
                    'verdict' => $memory->verdict,
                    'note' => $memory->note,
                    // Вердикт адміністратора для моделі — істина в останній інстанції.
                    'confirmed_by_admin' => $memory->source === EmployeeMemory::SOURCE_ADMIN,
                ])
                ->values()
                ->all(),
            'facts' => $rows
                ->where('kind', EmployeeMemory::KIND_FACT)
                ->map(fn (EmployeeMemory $memory) => [
                    'name' => $memory->name,
                    'note' => $memory->note,
                ])
                ->values()
                ->all(),
        ];
    }
}
