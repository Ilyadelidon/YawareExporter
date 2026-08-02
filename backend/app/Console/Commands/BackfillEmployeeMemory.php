<?php

namespace App\Console\Commands;

use App\Models\DailyAnalysis;
use App\Models\EmployeeMemory;
use App\Services\Ai\EmployeeMemoryService;
use Illuminate\Console\Command;

/**
 * Наповнює пам'ять із уже готових розборів — щоб не чекати, поки вона
 * набереться заново, і не платити за повторні пошуки з першого ж дня.
 */
class BackfillEmployeeMemory extends Command
{
    protected $signature = 'ai:backfill-memory
        {--employee= : Лише для одного працівника (ID)}
        {--fresh : Спершу стерти наявну пам\'ять AI (вердикти адміністратора лишаються)}';

    protected $description = "Побудувати пам'ять AI з наявних розборів днів";

    public function handle(EmployeeMemoryService $memory): int
    {
        if ($this->option('fresh')) {
            $deleted = EmployeeMemory::where('source', EmployeeMemory::SOURCE_AI)
                ->when($this->option('employee'), fn ($query, $id) => $query->where('employee_id', $id))
                ->delete();

            $this->warn("Стерто рядків пам'яті AI: {$deleted}");
        }

        $analyses = DailyAnalysis::where('status', DailyAnalysis::STATUS_COMPLETED)
            ->when($this->option('employee'), fn ($query, $id) => $query->where('employee_id', $id))
            // Від найстаріших до найновіших: свіжіший вердикт має перезаписати давніший.
            ->orderBy('date')
            ->get();

        if ($analyses->isEmpty()) {
            $this->warn('Готових розборів немає — пам\'ять будувати нема з чого.');

            return self::SUCCESS;
        }

        foreach ($analyses as $analysis) {
            $memory->remember(
                $analysis->employee_id,
                $analysis->result ?? [],
                $analysis->date->toDateString(),
            );
        }

        $this->info("Оброблено розборів: {$analyses->count()}.");

        $this->table(
            ['Працівник', 'Активностей у пам\'яті'],
            EmployeeMemory::with('employee')
                ->where('kind', EmployeeMemory::KIND_ACTIVITY)
                ->get()
                ->groupBy('employee_id')
                ->map(fn ($rows) => [$rows->first()->employee?->name ?? '—', $rows->count()])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }
}
