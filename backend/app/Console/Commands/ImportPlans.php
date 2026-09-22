<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Разовий перенос плану зі старої Google Таблиці. JSON готує
 * ops/plans/plan-sheet-to-json.py з xlsx-експорту таблиці.
 */
class ImportPlans extends Command
{
    protected $signature = 'plans:import
        {file : JSON від plan-sheet-to-json.py}
        {--map=* : Явна відповідність «Імʼя в таблиці=ID працівника», якщо імʼя не впізналось}
        {--dry-run : Лише показати, що буде імпортовано}';

    protected $description = 'Імпортувати плани проектів зі старої Google Таблиці';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("Файл не знайдено: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $projects = $data['projects'] ?? null;

        if (! is_array($projects)) {
            $this->error('У файлі немає списку projects — це не вивід plan-sheet-to-json.py.');

            return self::FAILURE;
        }

        $names = collect($projects)->flatMap(fn (array $p) => array_column($p['tasks'], 'assignee'))->unique()->values();
        $employees = $this->matchEmployees($names->all());

        if ($employees === null) {
            return self::FAILURE;
        }

        $existing = PlanProject::whereIn('name', array_column($projects, 'name'))->pluck('name');

        if ($existing->isNotEmpty()) {
            // Повторний запуск задублював би всі задачі — тож не перезаписуємо мовчки.
            $this->error('Проекти з такими назвами вже є: '.$existing->implode(', ').'. Видаліть їх або перейменуйте перед імпортом.');

            return self::FAILURE;
        }

        foreach ($projects as $project) {
            $marks = array_sum(array_map(fn (array $t) => count($t['days']), $project['tasks']));
            $this->line(sprintf('%s: %d задач, %d розділів, %d відміток днів', $project['name'], count($project['tasks']), count($project['sections']), $marks));
        }

        if ($this->option('dry-run')) {
            $this->info('Пробний запуск — у базу нічого не записано.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($projects, $employees) {
            foreach ($projects as $data) {
                $this->importProject($data, $employees);
            }
        });

        $this->info('Імпорт завершено.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Employee>  $employees
     */
    private function importProject(array $data, array $employees): void
    {
        $project = PlanProject::create(['name' => $data['name']]);

        $project->members()->sync(
            collect($data['tasks'])->map(fn (array $t) => $employees[$t['assignee']]->id)->unique()->values()->all()
        );

        $sections = [];

        foreach ($data['sections'] as $position => $section) {
            $sections[$section['name']] = $project->sections()->create([
                'name' => $section['name'],
                'note' => $section['note'] ?? null,
                'position' => $position + 1,
            ])->id;
        }

        foreach ($data['tasks'] as $position => $row) {
            $status = array_key_exists($row['status'], PlanTask::STATUS_LABELS) ? $row['status'] : PlanTask::STATUS_PENDING;

            $task = $project->tasks()->create([
                'employee_id' => $employees[$row['assignee']]->id,
                'plan_section_id' => $row['section'] !== null ? $sections[$row['section']] ?? null : null,
                'title' => Str::limit($row['title'], 1000, ''),
                'note' => $row['note'],
                'status' => $status,
                'position' => $position + 1,
            ]);

            foreach ($row['days'] as $day) {
                $task->days()->updateOrCreate(['date' => $day['date']], ['comment' => $day['comment']]);
            }

            if ($row['current']) {
                $employees[$row['assignee']]->update(['current_plan_task_id' => $task->id]);
            }
        }
    }

    /**
     * Імена в таблиці писали від руки: «Посохов Олексый», «Делідон Ілля» —
     * порядок слів і літери гуляють. Слово вважаємо тим самим при відстані
     * Левенштейна до 2, а людину — знайденою, лише якщо збіглися всі слова
     * і кандидат один.
     *
     * @param  list<string>  $names
     * @return array<string, Employee>|null
     */
    private function matchEmployees(array $names): ?array
    {
        $explicit = [];

        foreach ($this->option('map') as $pair) {
            [$name, $id] = array_pad(explode('=', $pair, 2), 2, null);
            $explicit[trim((string) $name)] = (int) $id;
        }

        $employees = Employee::all();
        $result = [];
        $failed = false;

        foreach ($names as $name) {
            if (isset($explicit[$name])) {
                $employee = $employees->firstWhere('id', $explicit[$name]);

                if ($employee === null) {
                    $this->error("Працівника з ID {$explicit[$name]} для «{$name}» немає.");
                    $failed = true;

                    continue;
                }

                $result[$name] = $employee;

                continue;
            }

            $candidates = $employees->filter(fn (Employee $e) => $this->sameName($name, $e->name))->values();

            if ($candidates->count() !== 1) {
                $this->error(sprintf(
                    '«%s» — %s. Вкажіть явно: --map="%s=ID".',
                    $name,
                    $candidates->isEmpty() ? 'не знайдено серед працівників' : 'кілька збігів: '.$candidates->map(fn ($e) => "{$e->name} (ID {$e->id})")->implode(', '),
                    $name,
                ));
                $failed = true;

                continue;
            }

            $result[$name] = $candidates->first();
            $this->line("«{$name}» → {$result[$name]->name} (ID {$result[$name]->id})");
        }

        return $failed ? null : $result;
    }

    private function sameName(string $a, string $b): bool
    {
        $words = fn (string $name) => preg_split('/\s+/u', trim(mb_strtolower($name)), -1, PREG_SPLIT_NO_EMPTY);
        $left = $words($a);
        $right = $words($b);

        if (count($left) === 0 || count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $word) {
            $index = collect($right)->search(fn (string $other) => $this->distance($word, $other) <= 2);

            if ($index === false) {
                return false;
            }

            unset($right[$index]);
        }

        return true;
    }

    /** levenshtein() рахує байти, а кирилиця — два байти на літеру. */
    private function distance(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $previous = range(0, count($b));

        foreach ($a as $i => $charA) {
            $current = [$i + 1];

            foreach ($b as $j => $charB) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($charA === $charB ? 0 : 1));
            }

            $previous = $current;
        }

        return $previous[count($b)];
    }
}
