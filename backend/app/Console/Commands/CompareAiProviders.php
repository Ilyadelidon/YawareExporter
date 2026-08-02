<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\AiAnalysisService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Прогін одного й того ж дня через кілька AI підряд — щоб бачити різницю в
 * висновках, часі й токенах. Нічого не пише в базу: збережений розбір дня
 * лишається таким, яким був.
 */
class CompareAiProviders extends Command
{
    protected $signature = 'ai:compare
        {employee : ID працівника}
        {date : Дата дня у форматі YYYY-MM-DD}
        {--provider=* : Які провайдери прогнати (типово всі налаштовані)}
        {--json= : Куди скласти повні відповіді у JSON}';

    protected $description = 'Порівняти AI-провайдерів на одному робочому дні';

    public function handle(AiAnalysisService $service): int
    {
        $report = Report::with('employee')
            ->where('employee_id', $this->argument('employee'))
            ->where('report_date', $this->argument('date'))
            ->where('status', Report::STATUS_COMPLETED)
            ->first();

        if (! $report) {
            $this->error('Готового звіту за цю дату немає — порівнювати нема на чому.');

            return self::FAILURE;
        }

        $names = $this->option('provider') ?: array_keys(AiAnalysisService::PROVIDERS);
        $providers = [];

        foreach ($names as $name) {
            if (! isset(AiAnalysisService::PROVIDERS[$name])) {
                $this->error("Невідомий провайдер: {$name}.");

                return self::FAILURE;
            }

            $provider = $service->provider($name);

            if (! $provider->isConfigured()) {
                $this->warn("{$provider->label()} пропущено: у .env немає {$provider->envKey()}.");

                continue;
            }

            $providers[] = $provider;
        }

        if (! $providers) {
            $this->error('Жодного налаштованого провайдера — нічого запускати.');

            return self::FAILURE;
        }

        $this->line("Працівник: {$report->employee->name} ({$report->employee->position})");
        $this->line("День: {$report->report_date->toDateString()}");
        $this->newLine();

        $outcomes = [];

        foreach ($providers as $provider) {
            $this->line("→ {$provider->label()}…");
            $started = microtime(true);

            try {
                $outcome = $service->analyse($report, $provider->name());
            } catch (Throwable $exception) {
                $this->error("   помилка: {$exception->getMessage()}");

                continue;
            }

            $outcomes[$provider->label()] = $outcome + ['seconds' => round(microtime(true) - $started, 1)];
        }

        if (! $outcomes) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['AI', 'Модель', 'Час, с', 'Вхід', 'Вихід', 'Незрозумілих', 'Тасок'],
            array_map(fn (string $label, array $outcome) => [
                $label,
                $outcome['model'],
                $outcome['seconds'],
                $outcome['input_tokens'],
                $outcome['output_tokens'],
                count($outcome['result']['unclear_activities']),
                count($outcome['result']['task_coverage']),
            ], array_keys($outcomes), $outcomes),
        );

        foreach ($outcomes as $label => $outcome) {
            $this->newLine();
            $this->info("── {$label} ".str_repeat('─', max(0, 60 - mb_strlen($label))));
            $this->line($outcome['result']['summary']);

            if ($outcome['result']['focus_assessment']) {
                $this->line($outcome['result']['focus_assessment']);
            }

            foreach ($outcome['result']['unclear_activities'] as $item) {
                $this->line(sprintf(
                    '  • %s — %s (%s хв): %s',
                    $item['name'],
                    $item['verdict'],
                    round($item['duration_seconds'] / 60),
                    $item['reasoning'],
                ));
            }
        }

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($outcomes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->newLine();
            $this->info("Повні відповіді: {$path}");
        }

        return self::SUCCESS;
    }
}
