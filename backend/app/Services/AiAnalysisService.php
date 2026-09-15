<?php

namespace App\Services;

use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Report;
use App\Services\Ai\AnalysisProvider;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\DeepseekProvider;
use App\Services\Ai\EmployeeMemoryService;
use App\Services\Ai\RuleViolations;
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

    /**
     * Стелі довжини для тексту, який пише сам працівник (назви й коментарі
     * тасок). Довший за це текст у назві таски — це вже не назва, а спроба
     * щось передати моделі; див. AnalysisPrompt::system() про межу даних.
     */
    private const MAX_TASK_TEXT = 300;

    /** Те саме для назв активностей: тут це домен або назва застосунку. */
    private const MAX_ACTIVITY_NAME = 120;

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
     * @param  ?array<string, mixed>  $context  Готові дані дня — щоб той, хто
     *                                          вже будував їх заради хеша, не
     *                                          збирав їх удруге.
     * @return array{result: array<string, mixed>, model: string, provider: string, input_tokens: int, output_tokens: int}
     */
    public function analyse(Report $report, ?string $providerName = null, ?array $context = null): array
    {
        $provider = $this->provider($providerName);
        $context ??= $this->buildContext($report);

        if (empty($context['activities']) && empty($context['tasks'])) {
            throw new RuntimeException('За цей день немає ні активностей, ні тасок — аналізувати нічого.');
        }

        $outcome = $provider->analyse($context);

        // Порушення за графіком дописуємо самі — вони рахуються з цифр дня і не
        // мають залежати від того, що модель прочитала в назвах тасок.
        $outcome['result'] = RuleViolations::merge($outcome['result'], $context);

        return $outcome + ['provider' => $provider->name()];
    }

    /**
     * Відбиток даних дня: поки він не змінився, новий запит до моделі дасть той
     * самий розбір і платити за нього вдруге немає за що.
     *
     * @param  array<string, mixed>  $context
     */
    public static function contextHash(array $context): string
    {
        return sha1((string) json_encode($context, JSON_UNESCAPED_UNICODE));
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
            ->where('date', $date)
            ->first();

        $activities = ActivityEntry::where('employee_id', $report->employee_id)
            ->where('date', $date)
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
                'name' => self::clean($entry->name, self::MAX_ACTIVITY_NAME),
                'productivity' => $entry->productivity,
                'category' => $entry->category,
                'duration_seconds' => $entry->duration_seconds,
            ])->all(),
            // Підказка моделі, з чого починати пошук незрозумілого.
            'uncategorized_names' => $activities
                ->filter(fn (ActivityEntry $entry) => $entry->category === null)
                ->map(fn (ActivityEntry $entry) => self::clean($entry->name, self::MAX_ACTIVITY_NAME))
                ->values()
                ->all(),
        ];
    }

    /**
     * Таски зі знімка звіту; у знімку лежить повний формат трекера, беремо потрібне.
     *
     * @return list<array<string, ?string>>
     */
    private function tasks(Report $report): array
    {
        return Collection::make($report->tasks ?? [])
            ->map(fn (array $task) => [
                'name' => self::clean($task['name'] ?? null, self::MAX_TASK_TEXT),
                'comment' => self::clean($task['comment'] ?? null, self::MAX_TASK_TEXT),
                'start' => $task['start'] ?? null,
                'due' => $task['due'] ?? null,
            ])
            ->all();
    }

    /**
     * Готує до промпту текст, який писала не наша система: схлопує переноси й
     * керуючі символи в пробіли й обрізає до стелі.
     *
     * Переноси прибираємо не заради краси: у JSON з даними дня рядок у кілька
     * абзаців візуально виглядає як окремий блок вказівок, а не як назва таски.
     * Обрізання ж знімає сенс писати в назву довгий текст для моделі — на
     * справжні назви тасок і домени стелі не впливають.
     */
    private static function clean(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return mb_substr($value, 0, $limit);
    }
}
