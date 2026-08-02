<?php

namespace App\Services;

use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Report;
use App\Services\Ai\AnalysisProvider;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\DeepseekProvider;
use App\Services\Ai\EmployeeMemoryService;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * AI-аналіз робочого дня працівника: посада + таски дня + активності з Yaware
 * подаються моделі, яка повертає структурований розбір і окремо виписує
 * незрозумілі сайти й додатки.
 *
 * Сервіс відповідає за дані дня; сам запит до моделі робить провайдер
 * (див. App\Services\Ai). Провайдерів кілька навмисно — щоб на одному й тому ж
 * дні порівнювати якість і ціну (php artisan ai:compare).
 */
class AiAnalysisService
{
    /** Стеля рядків активності в промпті — довгі дні інакше роздувають вхід. */
    private const MAX_ACTIVITIES = 200;

    /** @var array<string, class-string<AnalysisProvider>> */
    public const PROVIDERS = [
        'anthropic' => AnthropicProvider::class,
        'deepseek' => DeepseekProvider::class,
    ];

    /**
     * @param  ?string  $name  null — провайдер за замовчуванням із .env
     */
    public function provider(?string $name = null): AnalysisProvider
    {
        $name = $name ?: config('services.ai.provider');

        if (! isset(self::PROVIDERS[$name])) {
            throw new RuntimeException("Невідомий AI-провайдер: {$name}.");
        }

        return app(self::PROVIDERS[$name]);
    }

    /**
     * @return list<AnalysisProvider>
     */
    public function providers(): array
    {
        return array_map(fn (string $class) => app($class), array_values(self::PROVIDERS));
    }

    /**
     * Чи можна запустити розбір саме цим провайдером (null — тим, що в .env).
     */
    public function isConfigured(?string $name = null): bool
    {
        return $this->provider($name)->isConfigured();
    }

    /**
     * Чи є ключ хоч до одного провайдера — цим гейтиться сам розділ аналітики.
     */
    public function hasAnyConfigured(): bool
    {
        foreach ($this->providers() as $provider) {
            if ($provider->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{result: array<string, mixed>, model: string, provider: string, input_tokens: int, output_tokens: int}
     */
    public function analyse(Report $report, ?string $providerName = null): array
    {
        $provider = $this->provider($providerName);
        $context = $this->buildContext($report);

        if (empty($context['activities']) && empty($context['tasks'])) {
            throw new RuntimeException('За цей день немає ні активностей, ні тасок — аналізувати нічого.');
        }

        return $provider->analyse($context) + ['provider' => $provider->name()];
    }

    /**
     * Дані дня в компактному вигляді для промпту.
     *
     * @return array<string, mixed>
     */
    public function buildContext(Report $report): array
    {
        $date = $report->report_date->toDateString();
        $employee = $report->employee;

        $stat = DailyStat::where('employee_id', $report->employee_id)
            ->whereDate('date', $date)
            ->first();

        $activities = ActivityEntry::where('employee_id', $report->employee_id)
            ->whereDate('date', $date)
            ->where('duration_seconds', '>', 0)
            ->orderByDesc('duration_seconds')
            ->limit(self::MAX_ACTIVITIES)
            ->get();

        return [
            'employee' => [
                'name' => $employee->name,
                'position' => $employee->position ?: 'посаду не вказано',
            ],
            'date' => $date,
            'day_stats' => $stat ? [
                'first_action' => $stat->first_action,
                'last_action' => $stat->last_action,
                'lateness_seconds' => $stat->lateness_seconds,
                'left_early_seconds' => $stat->left_early_seconds,
                'productive_seconds' => $stat->productive_seconds,
                'neutral_seconds' => $stat->neutral_seconds,
                'unproductive_seconds' => $stat->unproductive_seconds,
                'total_seconds' => $stat->total_seconds,
            ] : null,
            'tasks' => $this->tasks($report),
            // Що вже з'ясовано про активності цього працівника раніше — щоб
            // модель не перешукувала ті самі домени щодня.
            'memory' => app(EmployeeMemoryService::class)->forPrompt($report->employee_id),
            'activities' => $activities->map(fn (ActivityEntry $entry) => [
                'name' => $entry->name,
                'productivity' => $entry->productivity,
                'category' => $entry->category,
                'duration_seconds' => $entry->duration_seconds,
            ])->all(),
            // Підказка моделі, з чого починати пошук незрозумілого.
            'uncategorized_names' => $activities
                ->filter(fn (ActivityEntry $entry) => $entry->category === null)
                ->pluck('name')
                ->values()
                ->all(),
        ];
    }

    /**
     * Таски зі знімка звіту; у знімку лежить повний формат Trello, беремо потрібне.
     *
     * @return list<array<string, ?string>>
     */
    private function tasks(Report $report): array
    {
        return Collection::make($report->trello_tasks ?? [])
            ->map(fn (array $task) => [
                'name' => $task['name'] ?? null,
                'comment' => $task['comment'] ?? null,
                'start' => $task['start'] ?? null,
                'due' => $task['due'] ?? null,
            ])
            ->all();
    }
}
