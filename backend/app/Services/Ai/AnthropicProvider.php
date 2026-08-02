<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;
use Anthropic\Messages\ThinkingConfigAdaptive;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolUseBlock;
use Anthropic\Messages\WebSearchTool20260209;
use RuntimeException;

/**
 * Розбір дня через Claude.
 *
 * Результат забирається не з тексту відповіді, а з виклику інструмента
 * submit_analysis зі strict-схемою — так формат гарантований, і водночас
 * модель може перед цим сходити у web_search за незнайомими доменами
 * (structured outputs через output_config сусідять із server-side пошуком гірше).
 */
class AnthropicProvider implements AnalysisProvider
{
    /** Скільки разів продовжуємо відповідь після pause_turn (ліміт ітерацій web_search). */
    private const MAX_CONTINUATIONS = 3;

    public function name(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Claude';
    }

    public function envKey(): string
    {
        return 'ANTHROPIC_API_KEY';
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.anthropic.key');
    }

    public function analyse(array $context): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ANTHROPIC_API_KEY не заповнено — аналіз через Claude недоступний.');
        }

        $model = config('services.anthropic.model');
        $client = new Client(apiKey: config('services.anthropic.key'));

        $messages = [[
            'role' => 'user',
            'content' => "Дані робочого дня:\n\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]];

        $response = null;
        $usage = ['input' => 0, 'output' => 0];

        for ($attempt = 0; $attempt <= self::MAX_CONTINUATIONS; $attempt++) {
            $response = $this->request($client, $model, $messages);

            $usage['input'] += $response->usage->inputTokens;
            $usage['output'] += $response->usage->outputTokens;

            // pause_turn — сервер уперся в ліміт ітерацій web_search; дописуємо
            // відповідь у історію і просимо продовжити тим самим запитом.
            if ($response->stopReason !== 'pause_turn') {
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
        }

        if ($response->stopReason === 'refusal') {
            throw new RuntimeException('Модель відмовилася аналізувати ці дані.');
        }

        return [
            'result' => AnalysisPrompt::normalise($this->extractAnalysis($response)),
            'model' => $response->model,
            'input_tokens' => $usage['input'],
            'output_tokens' => $usage['output'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function request(Client $client, string $model, array $messages): Message
    {
        try {
            return $client->messages->create(
                maxTokens: 8000,
                messages: $messages,
                model: $model,
                outputConfig: ['effort' => config('services.anthropic.effort')],
                // Системний блок однаковий для всіх працівників і днів — кешуємо,
                // щоб ранковий прогін по всій команді не переплачував за нього.
                system: [[
                    'type' => 'text',
                    'text' => AnalysisPrompt::system(webSearch: true)
                        ."\n\nЗавершуй роботу викликом інструмента submit_analysis.",
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                thinking: ThinkingConfigAdaptive::with(),
                tools: [
                    WebSearchTool20260209::with(maxUses: 5),
                    Tool::with(
                        inputSchema: AnalysisPrompt::responseSchema(),
                        name: 'submit_analysis',
                        description: 'Надіслати готовий розбір робочого дня. Викликати рівно один раз, останнім кроком.',
                        strict: true,
                    ),
                ],
            );
        } catch (AuthenticationException) {
            throw new RuntimeException('Ключ ANTHROPIC_API_KEY відхилено — перевірте його в .env.');
        } catch (RateLimitException) {
            throw new RuntimeException('Ліміт запитів до Claude вичерпано — спробуйте пізніше.');
        } catch (APIConnectionException $exception) {
            throw new RuntimeException('Не вдалося зʼєднатися з Claude API: '.$exception->getMessage());
        }
    }

    /**
     * Витягує аргументи виклику submit_analysis — саме вони і є результатом.
     *
     * @return array<string, mixed>
     */
    private function extractAnalysis(Message $response): array
    {
        foreach ($response->content as $block) {
            if ($block instanceof ToolUseBlock && $block->name === 'submit_analysis') {
                return $block->input;
            }
        }

        throw new RuntimeException(
            'Модель не повернула структурований розбір (stop_reason: '.($response->stopReason ?? 'null').').'
        );
    }
}
