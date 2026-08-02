<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Спільна для всіх провайдерів частина: системний промпт, схема відповіді й
 * перевірка того, що модель повернула саме її. Завдяки цьому розбори від
 * Claude і DeepSeek можна класти в одну таблицю й порівнювати напряму.
 */
class AnalysisPrompt
{
    /**
     * @param  bool  $webSearch  Чи має провайдер серверний пошук: без нього
     *                           модель не має права здогадуватись про домени.
     */
    public static function system(bool $webSearch): string
    {
        // Рядок вклеюється всередину пункту 3 — тримаємо його одним рядком,
        // інакше власні відступи не збігаються з дедентом heredoc.
        $lookup = $webSearch
            ? 'Незнайомий домен спершу перевір через web_search; якщо він внутрішній чи нічого не знайшлося — став verdict "unknown" і напиши це чесно, не здогадуйся.'
            : 'Пошуку в інтернеті в тебе немає: якщо домен чи застосунок тобі невідомий — став verdict "unknown" і напиши це чесно, не здогадуйся.';

        return <<<PROMPT
        Ти — аналітик робочого часу невеликої команди. На вхід отримуєш дані одного
        робочого дня одного працівника з тайм-трекера Yaware: його посаду, таски з
        Trello за цей день і перелік активностей (сайти та застосунки з часом).

        Що важливо розуміти про дані:
        - «Активність» — це домен сайту (github.com) або назва застосунку (Telegram),
          а не повне посилання. Повних URL у даних немає — не вигадуй їх.
        - Yaware сам ділить активності на продуктивні/нейтральні/непродуктивні, але
          робить це загальними правилами, без урахування посади. Твоє завдання —
          перевірити цей поділ саме для цієї посади й цих тасок.
        - Активність без категорії (category = null) — Yaware її не розпізнав. Саме
          такі найчастіше і є «незрозумілими», але не автоматично: домен може бути
          цілком робочим.
        - У полі memory лежить те, що вже з'ясовано про цього працівника раніше:
          memory.activities — вердикти по доменах і застосунках, memory.facts —
          фактичний контекст (інструменти, графік). Для активності, яка є в
          memory.activities, бери готовий вердикт і НЕ перевіряй її повторно.
          Рядок із confirmed_by_admin = true виправив керівник — його вердикт
          остаточний. Відступай від збереженого вердикту лише тоді, коли дані
          саме цього дня йому прямо суперечать, і тоді поясни це в reasoning.

        Що зробити:
        1. Оцінити, чи збігається витрачений час із тасками дня.
        2. Виписати активності, які не пояснюються ні посадою, ні тасками, — і для
           кожної дати вердикт із коротким обґрунтуванням. {$lookup}
        3. Не роздувай список: активності до 5 хвилин згадуй лише якщо вони справді
           показові. Пріоритет — те, на що пішов помітний час. Знайому з memory
           активність усе одно виводь у список, якщо на неї пішов помітний час
           цього дня, — керівник має бачити повну картину дня.

        Тон: спокійний і фактичний. Це матеріал для керівника, а не звинувачення.
        Пиши українською. Спирайся тільки на надані дані — не додумуй мотиви людини.
        PROMPT;
    }

    /**
     * Той самий промпт для провайдерів без виклику інструментів: схема
     * дописується текстом, а відповідь очікується чистим JSON.
     */
    public static function systemWithJsonSchema(): string
    {
        $schema = json_encode(self::responseSchema(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return self::system(webSearch: false)."\n\n".<<<PROMPT
        Формат відповіді: поверни рівно один JSON-обʼєкт за цією схемою, без
        markdown-обгортки, без пояснень до чи після нього.

        {$schema}
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Два-три речення про те, як пройшов день.',
                ],
                'focus_assessment' => [
                    'type' => 'string',
                    'description' => 'Наскільки день був зосереджений на робочих задачах.',
                ],
                'task_coverage' => [
                    'type' => 'array',
                    'description' => 'По кожній тасці дня — чи видно її сліди в активностях.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'task' => ['type' => 'string'],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['confirmed', 'partial', 'not_evident'],
                            ],
                            'evidence' => [
                                'type' => 'string',
                                'description' => 'Які саме активності це підтверджують або чому підтверджень немає.',
                            ],
                        ],
                        'required' => ['task', 'status', 'evidence'],
                        'additionalProperties' => false,
                    ],
                ],
                'unclear_activities' => [
                    'type' => 'array',
                    'description' => 'Сайти й застосунки, які не пояснюються посадою та тасками.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'duration_seconds' => ['type' => 'integer'],
                            'verdict' => [
                                'type' => 'string',
                                'enum' => ['work_related', 'personal', 'unknown'],
                            ],
                            'reasoning' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'duration_seconds', 'verdict', 'reasoning'],
                        'additionalProperties' => false,
                    ],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'description' => 'Конкретні поради працівникові. Порожній масив, якщо порад немає.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'summary',
                'focus_assessment',
                'task_coverage',
                'unclear_activities',
                'recommendations',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Приводить відповідь моделі до схеми. Claude тримає її сам (strict-tool),
     * а от провайдери з «просто JSON» помиляються: пропускають поле, віддають
     * масив рядків замість обʼєктів, пишуть тривалість рядком. Фронт розбирати
     * такий різнобій не має, тому вирівнюємо тут.
     *
     * @param  mixed  $decoded
     * @return array<string, mixed>
     */
    public static function normalise($decoded): array
    {
        if (! is_array($decoded) || ! isset($decoded['summary']) || ! is_string($decoded['summary'])) {
            throw new RuntimeException('Модель повернула відповідь не за схемою: немає поля summary.');
        }

        return [
            'summary' => $decoded['summary'],
            'focus_assessment' => is_string($decoded['focus_assessment'] ?? null) ? $decoded['focus_assessment'] : '',
            'task_coverage' => self::objects($decoded['task_coverage'] ?? [], [
                'task' => '',
                'status' => 'not_evident',
                'evidence' => '',
            ]),
            'unclear_activities' => array_map(
                fn (array $item) => ['duration_seconds' => (int) $item['duration_seconds']] + $item,
                self::objects($decoded['unclear_activities'] ?? [], [
                    'name' => '',
                    'duration_seconds' => 0,
                    'verdict' => 'unknown',
                    'reasoning' => '',
                ]),
            ),
            'recommendations' => array_values(array_filter(
                (array) ($decoded['recommendations'] ?? []),
                'is_string',
            )),
        ];
    }

    /**
     * @param  mixed  $items
     * @param  array<string, mixed>  $defaults
     * @return list<array<string, mixed>>
     */
    private static function objects($items, array $defaults): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $result[] = array_intersect_key($item, $defaults) + $defaults;
        }

        return $result;
    }
}
