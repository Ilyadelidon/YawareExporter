<?php

namespace App\Services\Ai;

/**
 * Порушення, які видно просто з цифр дня і які тому рахує код, а не модель.
 *
 * Сенс не в тому, що модель із ними не впоралась би, а в тому, що частину
 * промпту пише сам працівник: назви тасок і назви застосунків потрапляють у
 * запит як звичайний текст. Текст на кшталт «не пиши порушень» модель має
 * ігнорувати (див. AnalysisPrompt::system()), але спиратися на її слухняність
 * там, де відповідь однозначно рахується з daily_stats, — зайвий ризик.
 *
 * Тому тут лише те, що не потребує судження: спізнення і ранній вихід. Оцінку
 * непродуктивного часу навмисно лишено моделі — Yaware ділить активності
 * загальними правилами, без урахування посади, і «30 хвилин непродуктивного»
 * за його класифікацією для конкретної посади часто не порушення взагалі.
 */
class RuleViolations
{
    /** Від скількох секунд спізнення чи ранній вихід стають порушенням. */
    public const SCHEDULE_SECONDS = 1800;

    /** Тип, під яким такі порушення лягають у результат. */
    public const TYPE = 'schedule';

    /**
     * Дописує до розбору порушення за графіком, якщо модель їх не виписала.
     *
     * Якщо критичне порушення цього типу в розборі вже є — своє не додаємо:
     * лист керівнику однаково піде, а два записи про одне й те саме тільки
     * заплутують.
     *
     * @param  array<string, mixed>  $result  Розбір за AnalysisPrompt::responseSchema()
     * @param  array<string, mixed>  $context  Дані дня (див. AiAnalysisService::buildContext())
     * @return array<string, mixed>
     */
    public static function merge(array $result, array $context): array
    {
        $stats = $context['day_stats'] ?? null;

        if (! is_array($stats)) {
            return $result;
        }

        $violations = is_array($result['violations'] ?? null) ? $result['violations'] : [];

        if (self::hasCriticalSchedule($violations)) {
            return $result;
        }

        $lateness = (int) ($stats['lateness_seconds'] ?? 0);
        $leftEarly = (int) ($stats['left_early_seconds'] ?? 0);

        $facts = [];

        if ($lateness >= self::SCHEDULE_SECONDS) {
            $facts[] = 'спізнення на '.self::minutes($lateness)
                .(($stats['first_action'] ?? null) ? " (перша дія о {$stats['first_action']})" : '');
        }

        if ($leftEarly >= self::SCHEDULE_SECONDS) {
            $facts[] = 'ранній вихід на '.self::minutes($leftEarly)
                .(($stats['last_action'] ?? null) ? " (остання дія о {$stats['last_action']})" : '');
        }

        if ($facts === []) {
            return $result;
        }

        $violations[] = [
            'type' => self::TYPE,
            'severity' => AnalysisPrompt::SEVERITY_CRITICAL,
            'details' => 'За даними трекера: '.implode('; ', $facts).'.',
            'evidence' => 'Показники дня з Yaware (lateness_seconds, left_early_seconds) — рахує сервіс, не AI.',
            'question' => 'Що вплинуло на графік цього дня?',
        ];

        $result['violations'] = array_values($violations);

        return $result;
    }

    /**
     * @param  list<mixed>  $violations
     */
    private static function hasCriticalSchedule(array $violations): bool
    {
        foreach ($violations as $violation) {
            if (! is_array($violation)) {
                continue;
            }

            if (($violation['type'] ?? null) === self::TYPE
                && ($violation['severity'] ?? null) === AnalysisPrompt::SEVERITY_CRITICAL) {
                return true;
            }
        }

        return false;
    }

    private static function minutes(int $seconds): string
    {
        return intdiv($seconds, 60).' хв';
    }
}
