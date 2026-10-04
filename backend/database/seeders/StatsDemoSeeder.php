<?php

namespace Database\Seeders;

use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\PlanTaskDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

/**
 * Тестові дані для перевірки Статистики локально: дні активності, діяльності
 * й відмітки днів у Планах з 1 липня по сьогодні. Наявні дні не чіпає,
 * тож запуск повторно лише дозаповнює прогалини.
 *
 *   php artisan db:seed --class=StatsDemoSeeder
 */
class StatsDemoSeeder extends Seeder
{
    private const FROM = '2026-07-01';

    /** Діяльності: назва, категорія, продуктивність, частка відповідного часу. */
    private const ACTIVITIES = [
        'productive' => [
            ['VS Code', 'Розробка', 0.5],
            ['Chrome — GitHub', 'Розробка', 0.2],
            ['Trello', 'Менеджмент', 0.15],
            ['Figma', 'Дизайн', 0.15],
        ],
        'neutral' => [
            ['Telegram', 'Месенджери', 0.6],
            ['Google Meet', 'Зустрічі', 0.4],
        ],
        'unproductive' => [
            ['YouTube', 'Розваги', 0.65],
            ['Instagram', 'Соцмережі', 0.35],
        ],
    ];

    /** Профіль працівника: базовий продуктивний час (год) і схильність до запізнень. */
    private const PROFILES = [
        ['productive' => 6.4, 'lateness' => 0.25, 'focus' => 'VS Code'],
        ['productive' => 5.6, 'lateness' => 0.45, 'focus' => 'Trello'],
        ['productive' => 6.0, 'lateness' => 0.15, 'focus' => 'Figma'],
        ['productive' => 5.2, 'lateness' => 0.35, 'focus' => 'Chrome — GitHub'],
    ];

    /** Множник продуктивності за днем тижня: пік у середу, спад у понеділок і п'ятницю. */
    private const WEEKDAY_FACTOR = [1 => 0.88, 2 => 1.0, 3 => 1.08, 4 => 1.02, 5 => 0.82, 6 => 0.55, 7 => 0.5];

    public function run(): void
    {
        mt_srand(20261004);

        $employees = Employee::whereNull('dismissed_at')->orderBy('id')->get();
        $period = CarbonPeriod::create(self::FROM, CarbonImmutable::today()->subDay());
        $created = 0;

        foreach ($employees as $index => $employee) {
            $profile = self::PROFILES[$index % count(self::PROFILES)];
            $existing = DailyStat::where('employee_id', $employee->id)
                ->pluck('date')
                ->map(fn ($date) => $date->toDateString())
                ->flip();

            foreach ($period as $date) {
                $date = CarbonImmutable::parse($date);
                if ($existing->has($date->toDateString()) || ! $this->works($date)) {
                    continue;
                }

                $this->seedDay($employee, $date, $profile);
                $created++;
            }
        }

        $planDays = $this->seedPlanDays($employees);

        $this->command?->info("Додано днів статистики: {$created}, відміток у Планах: {$planDays}");
    }

    private function works(CarbonImmutable $date): bool
    {
        if ($date->isSunday()) {
            return mt_rand(1, 100) <= 3;
        }
        if ($date->isSaturday()) {
            return mt_rand(1, 100) <= 12;
        }

        // Відпустки / лікарняні.
        return mt_rand(1, 100) > 5;
    }

    private function seedDay(Employee $employee, CarbonImmutable $date, array $profile): void
    {
        $factor = self::WEEKDAY_FACTOR[$date->dayOfWeekIso] * $this->jitter(0.15);
        $productive = (int) ($profile['productive'] * 3600 * $factor);
        $neutral = (int) (mt_rand(50, 110) * 60 * $this->jitter(0.2));
        $unproductive = (int) (mt_rand(15, 70) * 60);
        $total = $productive + $neutral + $unproductive;

        $lateness = mt_rand(1, 100) <= $profile['lateness'] * 100 ? mt_rand(2, 45) * 60 : 0;
        $start = $date->setTime(9, 0)->addSeconds($lateness - (mt_rand(0, 15) * 60 * (int) ($lateness === 0)));
        $end = $start->addSeconds($total + mt_rand(30, 75) * 60);
        $leftEarly = $end->lessThan($date->setTime(18, 0)) ? $date->setTime(18, 0)->diffInSeconds($end, true) : 0;

        $stat = DailyStat::create([
            'employee_id' => $employee->id,
            'date' => $date->toDateString(),
            'first_action' => $start->format('H:i:s'),
            'last_action' => $end->format('H:i:s'),
            'lateness_seconds' => $lateness,
            'left_early_seconds' => (int) $leftEarly,
            'productive_seconds' => $productive,
            'unproductive_seconds' => $unproductive,
            'neutral_seconds' => $neutral,
            'total_seconds' => $total,
        ]);

        $this->seedActivities($stat, $profile['focus'], [
            'productive' => $productive,
            'neutral' => $neutral,
            'unproductive' => $unproductive,
        ]);
    }

    private function seedActivities(DailyStat $stat, string $focus, array $seconds): void
    {
        foreach (self::ACTIVITIES as $productivity => $activities) {
            $weights = array_map(
                fn ($activity) => $activity[2] * ($activity[0] === $focus ? 2.2 : 1) * $this->jitter(0.3),
                $activities,
            );
            $sum = array_sum($weights);

            foreach ($activities as $i => [$name, $category]) {
                ActivityEntry::create([
                    'employee_id' => $stat->employee_id,
                    'date' => $stat->date->toDateString(),
                    'productivity' => $productivity,
                    'name' => $name,
                    'category' => $category,
                    'duration_seconds' => (int) ($seconds[$productivity] * $weights[$i] / $sum),
                ]);
            }
        }
    }

    /**
     * Відмітки «працював над задачею» на робочі дні, де їх ще немає.
     * Працівникам без задач плану — по кілька задач у першому живому проекті.
     */
    private function seedPlanDays($employees): int
    {
        $project = PlanProject::whereNull('archived_at')->orderBy('id')->first();
        if (! $project) {
            return 0;
        }

        $created = 0;

        foreach ($employees as $employee) {
            $tasks = PlanTask::where('employee_id', $employee->id)
                ->whereIn('status', [PlanTask::STATUS_IN_PROGRESS, PlanTask::STATUS_REVIEW, PlanTask::STATUS_PAUSED, PlanTask::STATUS_PENDING, PlanTask::STATUS_DONE])
                ->orderByDesc('id')
                ->take(10)
                ->get();

            if ($tasks->count() < 4) {
                $tasks = $tasks->merge($this->demoTasks($project, $employee, 6 - $tasks->count()));
            }

            $statDates = DailyStat::where('employee_id', $employee->id)
                ->where('date', '>=', self::FROM)
                ->pluck('date')
                ->map(fn ($date) => $date->toDateString());

            foreach ($statDates as $date) {
                // 1–2 задачі на день, обрані з невеликого «поточного» набору.
                foreach ($tasks->random(min($tasks->count(), mt_rand(1, 2))) as $task) {
                    $day = PlanTaskDay::firstOrCreate(['plan_task_id' => $task->id, 'date' => $date]);
                    $created += (int) $day->wasRecentlyCreated;
                }
            }
        }

        return $created;
    }

    private function demoTasks(PlanProject $project, Employee $employee, int $count)
    {
        $titles = ['Звіт по метриках за місяць', 'Інтеграція з CRM', 'Онбординг нового клієнта', 'Оновити макети лендінгу', 'Рефакторинг імпорту', 'Тести для API оплат'];
        $statuses = [PlanTask::STATUS_IN_PROGRESS, PlanTask::STATUS_REVIEW, PlanTask::STATUS_DONE, PlanTask::STATUS_PENDING, PlanTask::STATUS_PAUSED, PlanTask::STATUS_IN_PROGRESS];

        return collect(range(0, $count - 1))->map(fn (int $i) => PlanTask::firstOrCreate(
            ['plan_project_id' => $project->id, 'employee_id' => $employee->id, 'title' => $titles[$i % 6]],
            ['status' => $statuses[$i % 6], 'position' => 1000 + $i],
        ));
    }

    private function jitter(float $spread): float
    {
        return 1 + (mt_rand(-1000, 1000) / 1000) * $spread;
    }
}
