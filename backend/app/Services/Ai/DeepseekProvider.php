<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Розбір дня через DeepSeek — альтернатива Claude для порівняння якості й ціни.
 *
 * API сумісне з OpenAI, тому окремого SDK не треба. Двох речей, які є в Claude,
 * тут немає, і це впливає на результат:
 *  - серверного web_search: незнайомі домени модель має чесно лишати "unknown"
 *    (про це прямо сказано в AnalysisPrompt::system(webSearch: false));
 *  - strict-схеми інструмента: формат тримається на response_format=json_object
 *    плюс схема текстом у промпті, тож відповідь ще й вирівнюється через
 *    AnalysisPrompt::normalise().
 */
class DeepseekProvider implements AnalysisProvider
{
    /** deepseek-reasoner на довгому дні думає помітно довше за Claude. */
    private const TIMEOUT = 300;

    public function name(): string
    {
        return 'deepseek';
    }

    public function label(): string
    {
        return 'DeepSeek';
    }

    public function envKey(): string
    {
        return 'DEEPSEEK_API_KEY';
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.deepseek.key');
    }

    public function analyse(array $context): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('DEEPSEEK_API_KEY не заповнено — аналіз через DeepSeek недоступний.');
        }

        $model = config('services.deepseek.model');
        $endpoint = rtrim(config('services.deepseek.base_url'), '/').'/chat/completions';

        try {
            $response = Http::withToken(config('services.deepseek.key'))
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->post($endpoint, [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => AnalysisPrompt::systemWithJsonSchema()],
                        [
                            'role' => 'user',
                            'content' => "Дані робочого дня:\n\n"
                                .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                        ],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 8000,
                    // Аналітика має бути відтворюваною; на reasoner параметр
                    // просто ігнорується, помилки не буде.
                    'temperature' => 0,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Не вдалося зʼєднатися з DeepSeek API: '.$exception->getMessage());
        }

        $this->assertOk($response);

        $choice = $response->json('choices.0') ?? [];

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new RuntimeException('Відповідь DeepSeek обірвалася на ліміті токенів — розбір неповний.');
        }

        return [
            'result' => AnalysisPrompt::normalise($this->decode($choice['message']['content'] ?? '')),
            'model' => $response->json('model') ?: $model,
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ];
    }

    private function assertOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(match ($response->status()) {
            401 => 'Ключ DEEPSEEK_API_KEY відхилено — перевірте його в .env.',
            402 => 'На балансі DeepSeek немає коштів — поповніть акаунт.',
            429 => 'Ліміт запитів до DeepSeek вичерпано — спробуйте пізніше.',
            default => 'DeepSeek API повернув помилку '.$response->status().': '
                .mb_substr((string) $response->json('error.message', $response->body()), 0, 300),
        });
    }

    /**
     * @return mixed
     */
    private function decode(string $content)
    {
        // json_object здебільшого віддає чистий JSON, але зрідка прилітає
        // ```json-обгортка — знімаємо її, щоб не втрачати готовий розбір.
        $trimmed = trim($content);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $trimmed));
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('DeepSeek повернув не-JSON: '.mb_substr($trimmed, 0, 300));
        }

        return $decoded;
    }
}
